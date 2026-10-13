<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Hepsijet;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
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
 * Native HepsiJet (Hepsiburada Logistics) driver (Turkey, REST).
 *
 * Supported (verified against the reference
 * `mohamedhekal/shipbridge-hepsijet` source: `HepsiJetClient` paths +
 * `HepsiJetDriver` flows + `config/hepsijet.php` status map): create
 * (`sendDeliveryOrderEnhanced`), track (`integration/track` with
 * `integrationStatus` + `details[]`), label (`barcodes-label`), return
 * (`deliveryType: RETURNED`), exchange (forward create), COD
 * (`deliveryTotalPrice`).
 *
 * Honestly disabled: cancel (no cancel endpoint in the reference client),
 * rates (no rates endpoint), pickup, webhooks, customs, pickup points.
 */
final class HepsijetDriver implements CarrierDriverInterface, SupportsLabels, SupportsTracking
{
    private readonly HepsijetPayloadFactory $payloads;

    public function __construct(
        private readonly HepsijetClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new HepsijetPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new HepsijetClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * HepsiJet `integrationStatus` values, per the reference
     * `config/hepsijet.php` status map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'NEO' => 'created',
            'ACCEPTED' => 'picked_up',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'FAILED_ATTEMPT' => 'exception',
            'RETURNED' => 'returned',
            'CANCELLED' => 'cancelled',
            'DELETED' => 'cancelled',
            'CREATED' => 'created',
            'PICKED_UP' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
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
            cancel: false,
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
        $data = $this->client->sendDeliveryOrder($built['payload']);
        $barcode = (string) ($data['customerDeliveryNo'] ?? $built['customer_delivery_no']);

        if ($barcode === '') {
            throw ShippingException::carrierFailed('HepsiJet create returned no customerDeliveryNo.');
        }

        $status = $built['shipment_kind'] === 'return'
            ? ShipmentStatus::Returned
            : ($built['shipment_kind'] === 'exchange' ? ShipmentStatus::Exchanged : ShipmentStatus::Created);

        return new ShipmentResult(
            id: $barcode,
            trackingNumber: $barcode,
            status: $status,
            carrier: $connection->carrierCode,
            raw: $this->sanitize([
                'customer_delivery_no' => $barcode,
                'response' => $data,
            ]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $payload = $this->client->track($this->payloads->track($trackingNumber));
        $deliveries = array_is_list($payload) ? $payload : [$payload];

        /** @var array<string, mixed> $delivery */
        $delivery = is_array($deliveries[0] ?? null) ? $deliveries[0] : [];

        if ($delivery === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $statusRaw = (string) ($delivery['integrationStatus'] ?? ShipmentStatus::InTransit->value);
        $barcode = (string) ($delivery['barcode'] ?? $trackingNumber);

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ((array) ($delivery['details'] ?? []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $eventStatus = (string) ($detail['integrationStatus'] ?? $statusRaw);

            if ($eventStatus === '') {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($eventStatus),
                description: $eventStatus,
                occurredAt: isset($detail['transactionDate']) ? (string) $detail['transactionDate'] : null,
                location: isset($detail['transactionLocation']) ? (string) $detail['transactionLocation'] : null,
            );
        }

        if ($events === []) {
            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'exception'),
                description: $statusRaw,
            );
        }

        return new TrackingResult(
            trackingNumber: $barcode !== '' ? $barcode : $trackingNumber,
            status: $events[0]->status,
            events: $events,
            raw: $this->sanitize(is_array($payload) ? $payload : ['response' => $payload]),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $payload = $this->client->fetchLabel([$shipmentId], 'PDF');
        $contents = $this->extractLabelContents(is_array($payload) ? $payload : []);

        if ($contents === '') {
            throw ShippingException::carrierFailed('HepsiJet label endpoint returned empty content.');
        }

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: ! str_starts_with($contents, '%PDF'),
            url: null,
        );
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function extractLabelContents(array $payload): string
    {
        if (isset($payload['label']) && is_string($payload['label'])) {
            return $payload['label'];
        }

        if (isset($payload['labels']) && is_array($payload['labels'])) {
            $first = $payload['labels'][0] ?? null;

            if (is_string($first)) {
                return $first;
            }

            if (is_array($first) && isset($first['label']) && is_string($first['label'])) {
                return $first['label'];
            }
        }

        if (isset($payload[0]) && is_string($payload[0])) {
            return $payload[0];
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            $row = $payload[0];

            return (string) ($row['label'] ?? $row['content'] ?? $row['data'] ?? '');
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
        foreach (['username', 'password', 'token', 'x_auth_token', 'x-auth-token', 'authorization', 'secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
