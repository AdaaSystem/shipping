<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Smsa;

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
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native SMSA Express carrier driver (KSA/GCC, SECOM SOAP via raw XML).
 *
 * Supported (WSDL-verified at track.smsaexpress.com/SECOM/SMSAwebService.asmx):
 * create (`addShip`), track (`getTrack` with `getStatus` fallback), label
 * (`getPDF`), cancel (`cancelShipment`), COD (`codAmt`), return/exchange via
 * the same `addShip` call with `metadata.ship_type` (`RET` vs `DLV`) —
 * mirroring the reference shipbridge-smsa driver and the Bosta type pattern.
 *
 * Honestly disabled: rates (`getShipCharges` exists in WSDL but is not
 * mapped — no verified pricing contract), pickup, webhooks (SECOM is
 * poll-based via getShipUpdates), customs, pickup points.
 */
final class SmsaDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsTracking
{
    private readonly SmsaPayloadFactory $payloads;

    public function __construct(
        private readonly SmsaSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new SmsaPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new SmsaSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'Shipment Created' => 'created',
            'Created' => 'created',
            'Picked up' => 'picked_up',
            'Picked Up' => 'picked_up',
            'Received at origin' => 'picked_up',
            'In transit' => 'in_transit',
            'In Transit' => 'in_transit',
            'Arrived' => 'in_transit',
            'Out for delivery' => 'out_for_delivery',
            'Out For Delivery' => 'out_for_delivery',
            'Delivered' => 'delivered',
            'Delivery confirmed' => 'delivered',
            'Cancelled' => 'cancelled',
            'Canceled' => 'cancelled',
            'Cancel' => 'cancelled',
            'Returned' => 'returned',
            'Returned to shipper' => 'returned',
            'Exception' => 'exception',
            'Failed' => 'exception',
            'On Hold' => 'exception',
            'Delayed' => 'exception',
            'CREATED' => 'created',
            'PICKED_UP' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'CANCELLED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'RETURNED' => 'returned',
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
            return: true,
            exchange: true,
            cod: true,
            webhooks: false,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $payload = $this->payloads->create($draft);
        $awb = $this->client->addShip($payload);

        return new ShipmentResult(
            id: $awb,
            trackingNumber: $awb,
            status: $this->normalizer->normalize('created'),
            carrier: $connection->carrierCode,
            raw: $this->sanitize(['awb' => $awb]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $track = $this->client->getTrack($trackingNumber);
        $events = $this->toTrackingEvents($track);

        if ($events !== []) {
            return new TrackingResult(
                trackingNumber: $trackingNumber,
                status: $events[0]->status,
                events: $events,
                raw: $this->sanitize($track['raw'] ?? ['request_status' => $track['requestStatus'] ?? '']),
            );
        }

        $statusData = $this->client->getStatus($trackingNumber);
        $statusRaw = (string) ($statusData['status'] ?? '');

        if ($statusRaw === '' || $this->isNotFound($statusRaw)) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $status = $this->normalizer->normalize($statusRaw);

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $status,
            events: [
                new TrackingEvent(
                    status: $status,
                    description: $statusRaw,
                ),
            ],
            raw: $this->sanitize($statusData['raw'] ?? ['status' => $statusRaw]),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $contents = $this->client->getPdf($shipmentId);

        if ($contents === '') {
            throw ShippingException::carrierFailed('SMSA getPDF returned an empty label.');
        }

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
        $reason = (string) ($connection->metadata['cancel_reason'] ?? $connection->metadata['reason'] ?? 'Cancelled by sender');

        $this->client->cancelShipment($shipmentId, $reason !== '' ? $reason : 'Cancelled by sender');

        return true;
    }

    /**
     * @param  array<string, mixed>  $track
     * @return list<TrackingEvent>
     */
    private function toTrackingEvents(array $track): array
    {
        /** @var list<TrackingEvent> $events */
        $events = [];

        $rows = $track['events'] ?? [];

        if (! is_array($rows)) {
            return $events;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $activity = (string) ($row['activity'] ?? $row['details'] ?? '');
            $description = (string) ($row['details'] ?? $activity);

            if ($activity === '' && $description === '') {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($activity !== '' ? $activity : $description),
                description: $description !== '' ? $description : $activity,
                occurredAt: isset($row['occurredAt']) && is_string($row['occurredAt']) ? $row['occurredAt'] : null,
                location: isset($row['location']) && is_string($row['location']) ? $row['location'] : null,
            );
        }

        return $events;
    }

    private function isNotFound(string $statusRaw): bool
    {
        $lower = strtolower($statusRaw);

        return str_contains($lower, 'not found')
            || str_contains($lower, 'no shipment')
            || str_contains($lower, 'no such')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'invalid awb');
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['passkey', 'pass_key', 'passKey', 'pass_Key', 'token', 'authorization', 'password', 'secret', 'client_secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
