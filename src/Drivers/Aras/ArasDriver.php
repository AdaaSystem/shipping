<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aras;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Contracts\SupportsTracking;
use Adaa\Shipping\DTOs\LabelResult;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\DTOs\ShipmentResult;
use Adaa\Shipping\DTOs\TrackingEvent;
use Adaa\Shipping\DTOs\TrackingResult;
use Adaa\Shipping\Enums\LabelFormat;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native Aras Kargo driver (Turkey, `arascargoservice.asmx` over raw SOAP).
 *
 * Supported (operation + field names verified against the live WSDL at
 * `customerws.araskargo.com.tr`, cross-checked with the reference
 * `mohamedhekal/shipbridge-aras` source + `config/aras.php` status map):
 * create (`SetOrder`, tracking key = `IntegrationCode`), track
 * (`GetQueryJSON` on the Esasweb tracking endpoint with `GetCargoInfo`
 * fallback), label (WSDL-verified `GetArasBarcode` → `ZebraPdf` — the
 * reference claims labels are not re-fetchable, but the operation exists on
 * the same WSDL, so it is wired with the base64 assumption documented),
 * cancel (`CancelDispatch`), COD (`CodAmount` + collection/billing types),
 * return/exchange via the same `SetOrder` call with
 * `metadata.shipment_kind`.
 *
 * Honestly disabled: rates (no rates operation), pickup, webhooks, customs,
 * pickup points.
 */
final class ArasDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsTracking
{
    private readonly ArasPayloadFactory $payloads;

    public function __construct(
        private readonly ArasSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new ArasPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new ArasSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * Turkish movement labels, per the reference `config/aras.php` map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'KAYIT EDILDI' => 'created',
            'KAYIT EDİLDİ' => 'created',
            'CIKIS SUBESINDE' => 'picked_up',
            'ÇIKIŞ ŞUBESİNDE' => 'picked_up',
            'TRANSFER MERKEZINDE' => 'in_transit',
            'TRANSFER MERKEZİNDE' => 'in_transit',
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
            label: true,
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
        $order = $this->payloads->create($draft);
        $integrationCode = $this->client->setOrder($order);

        return new ShipmentResult(
            id: $integrationCode,
            trackingNumber: $integrationCode,
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            raw: $this->sanitize(['integration_code' => $integrationCode]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $events = $this->queryEvents($trackingNumber);

        if ($events === []) {
            $cargo = $this->client->getCargoInfo($trackingNumber);
            $statusRaw = (string) ($cargo['status'] ?? '');

            if ($statusRaw === '') {
                $rows = $cargo['rows'] ?? [];

                if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
                    $statusRaw = $this->rowStatus($rows[0]);
                }
            }

            if ($statusRaw === '') {
                throw ShippingException::shipmentNotFound($trackingNumber);
            }

            $status = $this->normalizer->normalize($statusRaw);

            return new TrackingResult(
                trackingNumber: $trackingNumber,
                status: $status,
                events: [new TrackingEvent(status: $status, description: $statusRaw)],
                raw: $this->sanitize($cargo['raw'] ?? ['status' => $statusRaw]),
            );
        }

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $events[0]->status,
            events: $events,
            raw: $this->sanitize(['events' => array_map(static fn (TrackingEvent $e): array => [
                'status' => $e->status->value,
                'description' => $e->description,
                'occurred_at' => $e->occurredAt,
                'location' => $e->location,
            ], $events)]),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $contents = $this->client->getArasBarcode($shipmentId);

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: ! str_starts_with($contents, '%PDF'),
            url: null,
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $this->client->cancelDispatch($shipmentId);

        return true;
    }

    /**
     * @return list<TrackingEvent>
     */
    private function queryEvents(string $trackingNumber): array
    {
        try {
            $query = $this->client->getQueryJson($trackingNumber);
        } catch (ShippingException) {
            return [];
        }

        /** @var array<string, mixed>|list<mixed> $rows */
        $rows = $query;

        if (isset($query[0]) && is_array($query[0])) {
            $rows = $query[0];
        }

        $movements = null;

        if (is_array($rows)) {
            $movements = $rows['HAREKETLER'] ?? $rows['Hareketler'] ?? $rows['Movements'] ?? null;
        }

        if (! is_array($movements) || $movements === []) {
            $statusRaw = '';

            if (is_array($rows)) {
                $statusRaw = (string) ($rows['DURUM'] ?? $rows['Durum'] ?? '');
            }

            if ($statusRaw === '') {
                return [];
            }

            return [new TrackingEvent(
                status: $this->normalizer->normalize($statusRaw),
                description: $statusRaw,
            )];
        }

        if (isset($movements['DURUM']) || isset($movements['Durum'])) {
            $movements = [$movements];
        }

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ($movements as $row) {
            if (! is_array($row)) {
                continue;
            }

            $statusRaw = (string) ($row['DURUM'] ?? $row['Durum'] ?? $row['status'] ?? '');

            if ($statusRaw === '') {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($statusRaw),
                description: $statusRaw,
                occurredAt: isset($row['TARIH']) ? (string) $row['TARIH'] : (isset($row['Tarih']) ? (string) $row['Tarih'] : null),
                location: isset($row['SUBE']) ? (string) $row['SUBE'] : (isset($row['Sube']) ? (string) $row['Sube'] : null),
            );
        }

        return array_reverse($events);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowStatus(array $row): string
    {
        foreach (['DURUM', 'Durum', 'durum', 'status', 'Status'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }

        return '';
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['username', 'user_name', 'password', 'customer_code', 'token', 'authorization', 'secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
