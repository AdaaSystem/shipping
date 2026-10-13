<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ptt;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Contracts\SupportsTracking;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\DTOs\ShipmentResult;
use Adaa\Shipping\DTOs\TrackingEvent;
use Adaa\Shipping\DTOs\TrackingResult;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native PTT Kargo driver (Turkey, dual Axis2 SOAP services over raw XML).
 *
 * Supported (operation + field names verified against the live upload and
 * tracking WSDLs at `pttws.ptt.gov.tr`, cross-checked with the reference
 * `mohamedhekal/shipbridge-ptt` source + `config/ptt.php` status map):
 * create (`kabulEkle2`, `hataKodu === 1`, tracking key = pre-allocated
 * `barkodNo`), track (`barkodSorgu` detail with `sonIslemAciklama` +
 * kabul/teslim/iade milestones), cancel (`barkodVeriSil` with the
 * create-time `dosyaAdi` from connection metadata), COD
 * (`odeme_sart_ucreti` + `ekhizmet=OS`), return/exchange via the same
 * `kabulEkle2` call with `metadata.shipment_kind`.
 *
 * Honestly disabled: label (the upload WSDL only offers `etiketGetir`,
 * which returns EPL printer source — not PDF/ZPL/PNG — so it cannot
 * satisfy the label contract without a sandbox-verified conversion path),
 * rates (no rates operation), pickup, webhooks, customs, pickup points.
 */
final class PttDriver implements CarrierDriverInterface, SupportsCancellation, SupportsTracking
{
    private readonly PttPayloadFactory $payloads;

    public function __construct(
        private readonly PttSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new PttPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new PttSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * `gonderiDurumAciklama`-style Turkish labels, per the reference
     * `config/ptt.php` map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'KABUL EDILDI' => 'created',
            'KABUL EDİLDİ' => 'created',
            'KAYIT EDILDI' => 'created',
            'KAYIT EDİLDİ' => 'created',
            'TRANSFER' => 'in_transit',
            'DAGITIMDA' => 'out_for_delivery',
            'DAĞITIMDA' => 'out_for_delivery',
            'TESLIM EDILDI' => 'delivered',
            'TESLİM EDİLDİ' => 'delivered',
            'IADE' => 'returned',
            'İADE' => 'returned',
            'IPTAL' => 'cancelled',
            'İPTAL' => 'cancelled',
            'TASFİYE' => 'exception',
            'TASFİYE EDİLDİ' => 'exception',
            'CREATED' => 'created',
            'PICKED_UP' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'RETURNED' => 'returned',
            'CANCELLED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'EXCEPTION' => 'exception',
        ];
    }

    public function capabilities(): CarrierCapabilities
    {
        return new CarrierCapabilities(
            rates: false,
            create: true,
            label: false,
            track: true,
            cancel: true,
            pickup: false,
            return: true,
            exchange: true,
            cod: true,
            webhooks: false,
            customs: false,
            pickupPoints: false,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $built = $this->payloads->create($draft);
        $barcode = $this->client->kabulEkle2($built['input']);

        $kind = $built['shipment_kind'];

        $status = $kind === 'return'
            ? ShipmentStatus::Returned
            : ($kind === 'exchange' ? ShipmentStatus::Exchanged : ShipmentStatus::Created);

        return new ShipmentResult(
            id: $barcode,
            trackingNumber: $barcode,
            status: $status,
            carrier: $connection->carrierCode,
            raw: $this->sanitize([
                'barcode' => $barcode,
                'dosya_adi' => $built['dosya_adi'],
            ]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $result = $this->client->barkodSorgu($trackingNumber);
        $detail = $result['detail'];
        $statusRaw = (string) ($result['status'] ?? '');

        if ($statusRaw === '' && $this->isMissing($detail)) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        /** @var list<TrackingEvent> $events */
        $events = [];

        $kabulTarih = (string) ($detail['kabulTarih'] ?? '');
        $kabulIsyeri = (string) ($detail['kabulIsyeri'] ?? $detail['kabul_merkezi'] ?? '');

        if ($kabulTarih !== '') {
            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize('KABUL EDİLDİ'),
                description: 'Kabul'.($kabulIsyeri !== '' ? ' — '.$kabulIsyeri : ''),
                occurredAt: $kabulTarih,
                location: $kabulIsyeri !== '' ? $kabulIsyeri : null,
            );
        }

        $sonTarih = (string) ($detail['sonIslemTarih'] ?? '');
        $sonIsyeri = (string) ($detail['sonIslemIsyeri'] ?? '');

        if ($statusRaw !== '') {
            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($statusRaw),
                description: (string) ($detail['sonIslemAciklama'] ?? $statusRaw),
                occurredAt: $sonTarih !== '' ? $sonTarih : null,
                location: $sonIsyeri !== '' ? $sonIsyeri : null,
            );
        }

        $teslimTarih = (string) ($detail['aliciTeslimTarih'] ?? '');

        if ($teslimTarih !== '') {
            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize('TESLİM EDİLDİ'),
                description: 'Teslim'.(isset($detail['teslim_alan']) && is_string($detail['teslim_alan']) && $detail['teslim_alan'] !== '' ? ' — '.$detail['teslim_alan'] : ''),
                occurredAt: $teslimTarih,
            );
        }

        $iadeTarih = (string) ($detail['iadeTarih'] ?? '');

        if ($iadeTarih !== '') {
            $iadeNeden = (string) ($detail['iadeNedeni'] ?? '');

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize('İADE'),
                description: $iadeNeden !== '' ? $iadeNeden : 'İade',
                occurredAt: $iadeTarih,
                location: isset($detail['iadeIsyeri']) && is_string($detail['iadeIsyeri']) ? $detail['iadeIsyeri'] : null,
            );
        }

        if ($events === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        return new TrackingResult(
            trackingNumber: (string) ($detail['barkod'] ?? $trackingNumber),
            status: $events[count($events) - 1]->status,
            events: $events,
            raw: $this->sanitize($result['raw']),
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $dosyaAdi = (string) ($connection->metadata['dosya_adi'] ?? $connection->metadata['dosyaAdi'] ?? '');

        if ($dosyaAdi === '') {
            throw ShippingException::carrierFailed('PTT cancel needs the create-time dosyaAdi in connection metadata (raw.dosya_adi).');
        }

        $this->client->barkodVeriSil($shipmentId, $dosyaAdi);

        return true;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function isMissing(array $detail): bool
    {
        $hata = (int) ($detail['hataKodu'] ?? 0);
        $message = mb_strtolower((string) ($detail['aciklama'] ?? ''), 'UTF-8');

        if ($hata !== 0) {
            return true;
        }

        return str_contains($message, 'bulunamad')
            || str_contains($message, 'not found')
            || str_contains($message, 'no such')
            || str_contains($message, 'kayıt yok')
            || str_contains($message, 'kayit yok');
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['kullanici', 'sifre', 'musteri_id', 'musteri_no', 'token', 'authorization', 'secret', 'password', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
