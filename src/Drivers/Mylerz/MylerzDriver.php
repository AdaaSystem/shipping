<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mylerz;

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
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native Mylerz carrier driver (Egypt / MENA).
 *
 * Return and exchange travel through the create payload via
 * metadata.service_category = RETURN|EXCHANGE — Mylerz exposes only
 * AddOrders for creation, plus GetPackageListStatus for tracking and
 * GetAWB for labels. No public rates or webhooks endpoints.
 */
final class MylerzDriver implements CarrierDriverInterface, SupportsLabels, SupportsTracking
{
    private readonly MylerzPayloadFactory $payloads;

    public function __construct(
        private readonly MylerzClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new MylerzPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new MylerzClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'CREATED' => 'created',
            'READY FOR PICKUP' => 'created',
            'PICKED UP' => 'picked_up',
            'PICKEDUP' => 'picked_up',
            'IN TRANSIT' => 'in_transit',
            'IN_TRANSIT' => 'in_transit',
            'OUT FOR DELIVERY' => 'out_for_delivery',
            'OFD' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'RETURNED' => 'returned',
            'RETURN TO VENDOR' => 'returned',
            'CANCELLED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'EXCEPTION' => 'exception',
            'ON HOLD' => 'exception',
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
        $value = $this->client->addOrders($this->payloads->create($draft));

        /** @var array<string, mixed>|list<mixed> $packages */
        $packages = $value['Packages'] ?? $value;
        $first = is_array($packages) ? ($packages[0] ?? null) : null;

        if (! is_array($first)) {
            throw ShippingException::carrierFailed('Mylerz AddOrders returned no packages.');
        }

        $barcode = (string) ($first['BarCode'] ?? $first['Barcode'] ?? $first['barCode'] ?? '');
        $reference = (string) ($first['Reference'] ?? $draft->reference ?? '');
        $statusRaw = (string) ($first['Status'] ?? $first['PackageStatus'] ?? 'CREATED');

        if ($barcode === '') {
            throw ShippingException::carrierFailed('Mylerz AddOrders returned no barcode.');
        }

        return new ShipmentResult(
            id: $barcode !== '' ? $barcode : $reference,
            trackingNumber: $barcode,
            status: $this->normalizer->normalize($statusRaw),
            carrier: $connection->carrierCode,
            raw: $this->sanitize($value),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $rows = $this->client->packageListStatus([$trackingNumber]);
        $row = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

        if ($row === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        /** @var array<string, mixed> $row */
        $statusRaw = (string) ($row['Status'] ?? $row['PackageStatus'] ?? $row['EnName'] ?? $row['status'] ?? 'exception');
        $status = $this->normalizer->normalize($statusRaw);

        /** @var list<TrackingEvent> $events */
        $events = [];

        if ($statusRaw !== '') {
            $events[] = new TrackingEvent(
                status: $status,
                description: $statusRaw,
                occurredAt: isset($row['StatusDate']) ? (string) $row['StatusDate'] : null,
                location: isset($row['Hub']) ? (string) $row['Hub'] : null,
            );
        }

        return new TrackingResult(
            trackingNumber: (string) ($row['Barcode'] ?? $row['BarCode'] ?? $trackingNumber),
            status: $status,
            events: $events,
            raw: $this->sanitize($row),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $envelope = $this->client->getAwb($shipmentId);
        $value = $envelope['Value'] ?? $envelope;

        $contents = '';
        $url = null;

        if (is_string($value)) {
            $contents = $value;
        } elseif (is_array($value)) {
            $contents = (string) ($value['AWB'] ?? $value['Awb'] ?? $value['Pdf'] ?? $value['File'] ?? '');
            $url = isset($value['Url']) ? (string) $value['Url'] : (isset($value['URL']) ? (string) $value['URL'] : null);
        }

        if ($contents === '' && $url === null) {
            throw ShippingException::carrierFailed('Mylerz GetAWB returned empty label.');
        }

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: $contents !== '' && ! str_starts_with($contents, '%PDF'),
            url: $url,
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
