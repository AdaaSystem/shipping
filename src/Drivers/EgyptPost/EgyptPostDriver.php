<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\EgyptPost;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
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
 * Native Egypt Post driver (tracking-only).
 *
 * Egypt Post publishes no public merchant create-shipment API, so creation,
 * return, and exchange honestly throw unsupportedOperation and are never
 * advertised. label() returns the public TrackTrace portal URL with empty
 * contents (track_only parity with the reference driver).
 */
final class EgyptPostDriver implements CarrierDriverInterface, SupportsTracking
{
    public function __construct(
        private readonly EgyptPostClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {}

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new EgyptPostClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'REGISTERED' => 'created',
            'ACCEPTED' => 'created',
            'PICKED_UP' => 'picked_up',
            'DISPATCHED' => 'in_transit',
            'IN_TRANSIT' => 'in_transit',
            'ARRIVED' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'RETURNED' => 'returned',
            'FAILED' => 'exception',
            'UNDELIVERED' => 'exception',
            'تم التسليم' => 'delivered',
            'قيد التوصيل' => 'out_for_delivery',
            'تم القبول' => 'created',
        ];
    }

    public function capabilities(): CarrierCapabilities
    {
        return new CarrierCapabilities(
            rates: false,
            create: false,
            label: false,
            track: true,
            cancel: false,
            pickup: false,
            return: false,
            exchange: false,
            cod: false,
            webhooks: false,
            customs: false,
            pickupPoints: false,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        throw ShippingException::unsupportedOperation('egyptpost', 'createShipment');
    }

    public function createReturn(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        throw ShippingException::unsupportedOperation('egyptpost', 'createReturn');
    }

    public function createExchange(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        throw ShippingException::unsupportedOperation('egyptpost', 'createExchange');
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $payload = $this->client->trackOfficial($trackingNumber);
        $status = $this->normalizer->normalize((string) ($payload['status'] ?? 'exception'));

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ((array) ($payload['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize((string) ($event['status'] ?? $status->value)),
                description: (string) ($event['description'] ?? ''),
                occurredAt: isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
                location: isset($event['location']) ? (string) $event['location'] : null,
            );
        }

        $raw = isset($payload['raw']) && is_array($payload['raw']) ? $payload['raw'] : $payload;

        return new TrackingResult(
            trackingNumber: (string) ($payload['tracking_number'] ?? $trackingNumber),
            status: $status,
            events: $events,
            raw: $this->sanitize($raw),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: '',
            base64Encoded: false,
            url: $this->client->publicTrackUrl($shipmentId),
        );
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['api_key', 'apikey', 'apiKey', 'access_token', 'token', 'authorization', 'password', 'secret', 'client_secret', 'username'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
