<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Surat;

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
 * Native Sürat Kargo driver (Turkey, `services.asmx` over raw SOAP).
 *
 * Supported (verified against the reference
 * `mohamedhekal/shipbridge-surat` source: `SoapSuratGateway` op names +
 * `Support/PayloadFactory` field map + `config/surat.php` status map):
 * create (`GonderiyiKargoyaGonderYeniSiparisBarkodOlustur`, tracking key =
 * `OzelKargoTakipNo` with `TakipNo` barcode fallback), track
 * (`KargoTakipHareketDetayliV2` with `Hareket` rows), cancel
 * (`GonderiGeriCek`), COD (`KapidanOdemeTutar` / `KapidanOdeme`),
 * return/exchange via the same create operation with
 * `metadata.shipment_kind`.
 *
 * Honestly disabled: label (no re-fetch operation — labels, if any, come
 * back at create time; same as the reference, whose `label()` throws),
 * rates (no rates operation), pickup, webhooks, customs, pickup points.
 */
final class SuratDriver implements CarrierDriverInterface, SupportsCancellation, SupportsTracking
{
    private readonly SuratPayloadFactory $payloads;

    public function __construct(
        private readonly SuratSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new SuratPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new SuratSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * Turkish movement labels, per the reference `config/surat.php` map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'KAYIT EDILDI' => 'created',
            'KAYIT EDİLDİ' => 'created',
            'SUBE KABUL' => 'picked_up',
            'ŞUBE KABUL' => 'picked_up',
            'TRANSFER' => 'in_transit',
            'DAGITIMDA' => 'out_for_delivery',
            'DAĞITIMDA' => 'out_for_delivery',
            'TESLIM EDILDI' => 'delivered',
            'TESLİM EDİLDİ' => 'delivered',
            'IADE' => 'returned',
            'İADE' => 'returned',
            'IPTAL' => 'cancelled',
            'İPTAL' => 'cancelled',
            'SORUNLU' => 'exception',
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
        $params = $this->payloads->create($draft);
        $result = $this->client->createShipmentWithBarcode($params);

        $kind = strtolower((string) ($draft->metadata['shipment_kind'] ?? 'forward'));

        $status = $kind === 'return'
            ? ShipmentStatus::Returned
            : ($kind === 'exchange' ? ShipmentStatus::Exchanged : ShipmentStatus::Created);

        return new ShipmentResult(
            id: $result['tracking_number'],
            trackingNumber: $result['tracking_number'],
            status: $status,
            carrier: $connection->carrierCode,
            raw: $this->sanitize([
                'barcode' => $result['barcode'],
                'web_siparis_kodu' => $params['OzelKargoTakipNo'] ?? $result['tracking_number'],
                'message' => $result['message'],
            ]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $track = $this->client->trackDetailed($trackingNumber);

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ($track['events'] as $row) {
            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($row['durum']),
                description: $row['aciklama'] !== '' ? $row['aciklama'] : $row['durum'],
                occurredAt: $row['tarih'],
                location: $row['sube'],
            );
        }

        if ($events === []) {
            throw ShippingException::carrierFailed('Sürat tracking returned no events for '.$trackingNumber.'.');
        }

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $events[0]->status,
            events: $events,
            raw: $this->sanitize($track['raw']),
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $reason = (string) ($connection->metadata['cancel_reason'] ?? $connection->metadata['reason'] ?? 'Cancelled by merchant');

        $this->client->withdrawShipment($shipmentId, $reason !== '' ? $reason : 'Cancelled by merchant');

        return true;
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['kullanici_adi', 'cari', 'sifre', 'web_sifre', 'username', 'password', 'token', 'authorization', 'secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
