<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\JtExpress;

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
 * Native J&T Express carrier driver (Egypt, Open Platform).
 *
 * Supported (verified against genius-code/jt-express-eg, confirmed by
 * mohammedam33n/jt-express-laravel and shipbridge-jtexpress `docs/API.md`):
 * create (`addOrder`), track (`logistics/trace` by billCode), label
 * (`printOrder` by billCode), cancel (`cancelOrder` by txlogisticId),
 * COD (`payType=PP_CASH` + `itemsValue`), return/exchange via the same
 * `addOrder` call with `metadata.express_type` (`DR` vs `EX`) — mirroring the
 * shipbridge-jtexpress driver and the Bosta/Mylerz create-payload pattern.
 *
 * Honestly disabled: rates (no rates endpoint in any reference), pickup,
 * webhooks (track-subscription URL exists in one reference but no verified
 * signing/verification contract), customs, pickup points.
 *
 * Note the id split: `createShipment` returns `id = txlogisticId` (merchant
 * order id) and `trackingNumber = billCode` (waybill). Pass the billCode to
 * `track()`/`label()` and the id (txlogisticId) to `cancel()`.
 */
final class JtExpressDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsTracking
{
    private readonly JtExpressPayloadFactory $payloads;

    public function __construct(
        private readonly JtExpressClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new JtExpressPayloadFactory($this->client->config(), $this->client->bizDigest());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new JtExpressClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            '10' => 'created',
            '50' => 'picked_up',
            '100' => 'in_transit',
            '150' => 'out_for_delivery',
            '200' => 'delivered',
            '300' => 'returned',
            '400' => 'exception',
            'PICKED UP' => 'picked_up',
            'PICKUP' => 'picked_up',
            'IN TRANSIT' => 'in_transit',
            'DEPARTURE' => 'in_transit',
            'ARRIVAL' => 'in_transit',
            'OUT FOR DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'SIGNED' => 'delivered',
            'SIGNING' => 'delivered',
            'RETURNED' => 'returned',
            'RETURN' => 'returned',
            'PROBLEM' => 'exception',
            'EXCEPTION' => 'exception',
            'CREATED' => 'created',
            'PICKED_UP' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'RETURNED' => 'returned',
            'CANCELLED' => 'cancelled',
            'CANCELED' => 'cancelled',
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
        $response = $this->client->addOrder($this->payloads->create($draft));

        /** @var array<string, mixed> $data */
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $billCode = (string) ($data['billCode'] ?? '');
        $txlogisticId = (string) ($data['txlogisticId'] ?? '');

        if ($billCode === '' && $txlogisticId === '') {
            throw ShippingException::carrierFailed('J&T addOrder returned no billCode.');
        }

        return new ShipmentResult(
            id: $txlogisticId !== '' ? $txlogisticId : $billCode,
            trackingNumber: $billCode !== '' ? $billCode : $txlogisticId,
            status: $this->normalizer->normalize('created'),
            carrier: $connection->carrierCode,
            raw: $this->sanitize($response),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $response = $this->client->trace($trackingNumber);
        $rows = $this->traceRows($response);

        /** @var array<string, mixed> $first */
        $first = is_array($rows[0] ?? null) ? $rows[0] : [];

        if ($first === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        /** @var list<TrackingEvent> $events */
        $events = [];

        $details = $first['details'] ?? $first['traces'] ?? [];

        if (is_array($details)) {
            foreach ($details as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $statusRaw = (string) ($event['scanType'] ?? $event['status'] ?? $event['desc'] ?? '');

                if ($statusRaw === '') {
                    continue;
                }

                $events[] = new TrackingEvent(
                    status: $this->normalizer->normalize($statusRaw),
                    description: (string) ($event['desc'] ?? $event['scanType'] ?? $statusRaw),
                    occurredAt: isset($event['scanTime']) ? (string) $event['scanTime'] : (isset($event['time']) ? (string) $event['time'] : null),
                    location: isset($event['scanNetworkName']) ? (string) $event['scanNetworkName'] : (isset($event['location']) ? (string) $event['location'] : null),
                );
            }
        }

        $status = $events !== []
            ? $events[0]->status
            : $this->normalizer->normalize((string) ($first['status'] ?? $first['scanType'] ?? 'exception'));

        if ($events === [] && isset($first['status'])) {
            $events[] = new TrackingEvent(
                status: $status,
                description: (string) $first['status'],
            );
        }

        return new TrackingResult(
            trackingNumber: (string) ($first['billCode'] ?? $trackingNumber),
            status: $status,
            events: $events,
            raw: $this->sanitize($response),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $config = $this->client->config();
        $response = $this->client->printOrder(
            $shipmentId,
            (string) ($config['print_size'] ?? '0'),
            (int) ($config['print_code'] ?? 1),
        );

        /** @var array<string, mixed>|string|null $data */
        $data = $response['data'] ?? null;

        [$contents, $url] = $this->extractLabel($data);

        if ($contents === '' && $url === null) {
            throw ShippingException::carrierFailed('J&T printOrder returned an empty label.');
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
        $reason = (string) ($connection->metadata['cancel_reason'] ?? $connection->metadata['reason'] ?? 'Customer request');

        $this->client->cancelOrder($shipmentId, $reason !== '' ? $reason : 'Customer request');

        return true;
    }

    /**
     * Trace rows live under `data` (shipbridge shape: list) or nested
     * `data.data` (genius trackOrder shape).
     *
     * @param  array<string, mixed>  $response
     * @return list<mixed>
     */
    private function traceRows(array $response): array
    {
        $data = $response['data'] ?? [];

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @param  array<string, mixed>|string|null  $data
     * @return array{0: string, 1: ?string}
     */
    private function extractLabel(array|string|null $data): array
    {
        if (is_string($data)) {
            return $data !== '' ? [$data, null] : ['', null];
        }

        if (! is_array($data)) {
            return ['', null];
        }

        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $contents = (string) ($data['base64'] ?? $data['base64EncodeContent'] ?? $data['file'] ?? $data['pdf'] ?? '');
        $url = isset($data['url']) ? (string) $data['url'] : (isset($data['printUrl']) ? (string) $data['printUrl'] : null);

        return [$contents, $url !== '' ? $url : null];
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['api_account', 'apiAccount', 'private_key', 'privateKey', 'customer_pwd', 'customerPwd', 'password', 'customer_code', 'customerCode', 'digest', 'api_key', 'apikey', 'apiKey', 'token', 'authorization', 'secret', 'client_secret'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
