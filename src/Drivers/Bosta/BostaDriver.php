<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Bosta;

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
 * Native Bosta carrier driver (Egypt).
 *
 * Return (CRP type 25) and exchange (type 30) travel through the create
 * payload via metadata.type — Bosta has no separate return/exchange endpoint.
 */
final class BostaDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsTracking
{
    private readonly BostaPayloadFactory $payloads;

    public function __construct(
        private readonly BostaClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new BostaPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new BostaClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'Pickup requested' => 'created',
            'Waiting for route' => 'created',
            'Route Assigned' => 'created',
            'Waiting for Pickup' => 'created',
            'Picking up' => 'picked_up',
            'Picking up from warehouse' => 'picked_up',
            'Arrived at business' => 'picked_up',
            'Received at warehouse' => 'in_transit',
            'Picked up' => 'picked_up',
            'Picked up from business' => 'picked_up',
            'In transit between Hubs' => 'in_transit',
            'Delivering' => 'out_for_delivery',
            'Arrived at customer' => 'out_for_delivery',
            'Delivered' => 'delivered',
            'Delivery confirmed' => 'delivered',
            'Canceled' => 'cancelled',
            'Terminated' => 'cancelled',
            'Exception' => 'exception',
            'Returned to business' => 'returned',
            'Lost' => 'exception',
            'Damaged' => 'exception',
            'Investigation' => 'exception',
            'PICKED_UP' => 'picked_up',
            'RECEIVED_AT_WAREHOUSE' => 'in_transit',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'RETURNED_TO_BUSINESS' => 'returned',
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
            return: true,
            exchange: true,
            cod: true,
            webhooks: true,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $payload = $this->payloads->create($draft);
        $data = $this->client->createDelivery($payload);

        return $this->toShipmentResult($data, $connection);
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $data = $this->client->searchByTracking($trackingNumber);

        /** @var array<string, mixed> $delivery */
        $delivery = [];

        if (isset($data['deliveries'][0]) && is_array($data['deliveries'][0])) {
            $delivery = $data['deliveries'][0];
        } elseif (isset($data['trackingNumber']) || isset($data['state']) || isset($data['status'])) {
            $delivery = $data;
        }

        if ($delivery === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $statusRaw = $this->extractState($delivery);
        $status = $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'exception');

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ((array) ($delivery['tracking'] ?? $delivery['timeline'] ?? $delivery['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize(
                    (string) ($event['state']['value'] ?? $event['status'] ?? $event['value'] ?? $statusRaw)
                ),
                description: (string) ($event['description'] ?? $event['exceptionReason'] ?? $event['value'] ?? ''),
                occurredAt: isset($event['date']) ? (string) $event['date'] : (isset($event['createdAt']) ? (string) $event['createdAt'] : null),
                location: isset($event['hub']['name']) ? (string) $event['hub']['name'] : (isset($event['location']) ? (string) $event['location'] : null),
            );
        }

        return new TrackingResult(
            trackingNumber: (string) ($delivery['trackingNumber'] ?? $trackingNumber),
            status: $status,
            events: $events,
            raw: $this->sanitize($delivery),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $contents = '';
        $url = null;

        try {
            $envelope = $this->client->massAwb($shipmentId);
            [$contents, $url] = $this->extractLabel($envelope);
        } catch (ShippingException) {
            $contents = '';
            $url = null;
        }

        if ($contents === '' && $url === null) {
            $envelope = $this->client->awb($shipmentId);
            [$contents, $url] = $this->extractLabel($envelope);
        }

        if ($contents === '' && $url === null) {
            throw ShippingException::carrierFailed('Bosta AWB/label response was empty.');
        }

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: $contents !== '' && ! str_starts_with($contents, '%PDF'),
            url: $url,
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $this->client->terminate($shipmentId);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toShipmentResult(array $data, ShippingConnectionContext $connection): ShipmentResult
    {
        $id = (string) ($data['_id'] ?? $data['id'] ?? '');
        $tracking = (string) ($data['trackingNumber'] ?? $data['tracking_number'] ?? '');
        $statusRaw = $this->extractState($data);

        if ($id === '' && $tracking === '') {
            throw ShippingException::carrierFailed('Bosta create response had no shipment id.');
        }

        return new ShipmentResult(
            id: $id !== '' ? $id : $tracking,
            trackingNumber: $tracking !== '' ? $tracking : $id,
            status: $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'created'),
            carrier: $connection->carrierCode,
            raw: $this->sanitize($data),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractState(array $payload): string
    {
        $state = $payload['state'] ?? null;

        if (is_array($state)) {
            return (string) ($state['value'] ?? $state['code'] ?? '');
        }

        if (is_string($state)) {
            return $state;
        }

        return (string) ($payload['status'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{0: string, 1: ?string}
     */
    private function extractLabel(array $envelope): array
    {
        $raw = $envelope['data'] ?? null;

        if (is_string($raw) && $raw !== '') {
            return [$raw, isset($envelope['url']) && is_string($envelope['url']) ? $envelope['url'] : null];
        }

        if (is_array($raw)) {
            $contents = (string) ($raw['awb'] ?? $raw['data'] ?? $raw['pdf'] ?? '');
            $url = isset($raw['url']) ? (string) $raw['url'] : null;

            return [$contents, $url];
        }

        return ['', null];
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['api_key', 'apikey', 'apiKey', 'token', 'authorization', 'password', 'secret', 'client_secret'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
