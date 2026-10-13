<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Yurtici;

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
 * Native Yurtiçi Kargo driver (Turkey, Giden Kargo SOAP over raw XML).
 *
 * Supported (verified against the reference
 * `mohamedhekal/shipbridge-yurtici` source + `config/yurtici.php` status
 * map, cross-checked with `tcgunel/omniship-yurtici`): create
 * (`createShipment` + `ShippingOrderVO`, tracking key = `cargoKey`),
 * track (`queryShipment` with `operationStatus`/`operationCode` +
 * `invDocCargoVOArray` history), cancel (`cancelShipment` by cargoKey —
 * the shipbridge reference calls it `cancelDispatch`; the omniship source
 * proves `cancelShipment`, so that name is used with a `cancel_operation`
 * config override), COD (`ttInvoiceAmount` block), return/exchange via the
 * same `createShipment` call with `metadata.shipment_kind`.
 *
 * Honestly disabled: label (no label operation — print the cargoKey
 * barcode, same as the reference, whose `label()` throws), rates (no rates
 * operation), pickup, webhooks, customs, pickup points.
 */
final class YurticiDriver implements CarrierDriverInterface, SupportsCancellation, SupportsTracking
{
    private readonly YurticiPayloadFactory $payloads;

    public function __construct(
        private readonly YurticiSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new YurticiPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new YurticiSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * Shipment-level `operationStatus` letters (preferred) plus numeric
     * `operationCode` fallback, per the reference `config/yurtici.php` map
     * and omniship `OperationStatus`.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'NOP' => 'created',
            'IND' => 'out_for_delivery',
            'ISR' => 'in_transit',
            'CNL' => 'cancelled',
            'ISC' => 'cancelled',
            'DLV' => 'delivered',
            'BI' => 'cancelled',
            '0' => 'created',
            '1' => 'picked_up',
            '2' => 'in_transit',
            '3' => 'cancelled',
            '4' => 'out_for_delivery',
            '5' => 'delivered',
            '6' => 'returned',
            'GT' => 'picked_up',
            'BD' => 'created',
            'YK' => 'in_transit',
            'IN' => 'in_transit',
            'DG' => 'out_for_delivery',
            'TS' => 'delivered',
            'TE' => 'delivered',
            'IA' => 'returned',
            'Kargo İşlem Görmemiş' => 'created',
            'Kargo Teslimattadır' => 'out_for_delivery',
            'Kargo teslim edilmiştir' => 'delivered',
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
        $result = $this->client->createShipment($this->payloads->create($draft));
        $this->assertSuccess($result, 'createShipment');

        $cargoKey = (string) ($result['detail']['cargoKey'] ?? '');
        $jobId = $result['job_id'] !== '' ? $result['job_id'] : $cargoKey;

        if ($cargoKey === '' && $jobId === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo createShipment returned no cargoKey.');
        }

        return new ShipmentResult(
            id: $jobId !== '' ? $jobId : $cargoKey,
            trackingNumber: $cargoKey !== '' ? $cargoKey : $jobId,
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            raw: $this->sanitize($result['raw']),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $keyType = (int) ($connection->metadata['key_type'] ?? $this->client->config()['key_type'] ?? 0);
        $result = $this->client->queryShipment($this->payloads->query($trackingNumber, $keyType));
        $this->assertSuccess($result, 'queryShipment', $trackingNumber);

        $detail = $result['detail'];
        $statusRaw = (string) ($detail['operationStatus'] ?? $detail['operationCode'] ?? $detail['operationMessage'] ?? '');
        $docId = (string) ($detail['docId'] ?? $trackingNumber);
        $events = $this->parseEvents($detail, $statusRaw);

        return new TrackingResult(
            trackingNumber: $docId !== '' ? $docId : $trackingNumber,
            status: $events[0]->status,
            events: $events,
            raw: $this->sanitize($result['raw']),
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $result = $this->client->cancelShipment($this->payloads->cancel($shipmentId));
        $status = strtoupper(trim($result['operation_status']));

        if ($status === 'CNL' || $status === 'ISC') {
            return true;
        }

        throw ShippingException::carrierFailed(
            $result['operation_message'] !== ''
                ? 'Yurtiçi Kargo cancel failed: '.$result['operation_message']
                : 'Yurtiçi Kargo cancel failed (status '.$result['operation_status'].').'
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertSuccess(array $result, string $operation, ?string $trackingNumber = null): void
    {
        if (($result['out_flag'] ?? '') === '0') {
            return;
        }

        $message = (string) ($result['out_result'] ?? 'Yurtiçi Kargo '.$operation.' failed.');

        if ($trackingNumber !== null && $this->isNotFound($message)) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        throw ShippingException::carrierFailed(
            $message !== '' ? $message : 'Yurtiçi Kargo '.$operation.' failed.'
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<TrackingEvent>
     */
    private function parseEvents(array $detail, string $fallbackStatus): array
    {
        $history = $detail['invDocCargoVOArray'] ?? [];

        if (is_array($history) && (isset($history['operationMessage']) || isset($history['unitName']) || isset($history['operationCode']))) {
            $history = [$history];
        }

        /** @var list<TrackingEvent> $events */
        $events = [];

        if (is_array($history)) {
            foreach ($history as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $code = (string) ($row['operationStatus'] ?? $row['operationCode'] ?? $fallbackStatus);

                if ($code === '') {
                    continue;
                }

                $events[] = new TrackingEvent(
                    status: $this->normalizer->normalize($code),
                    description: (string) ($row['operationMessage'] ?? $row['cargoReasonExplanation'] ?? $code),
                    occurredAt: isset($row['operationDate']) ? (string) $row['operationDate'] : null,
                    location: isset($row['unitName']) ? (string) $row['unitName'] : null,
                );
            }
        }

        if ($events === []) {
            $code = $fallbackStatus !== '' ? $fallbackStatus : 'exception';

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($code),
                description: (string) ($detail['operationMessage'] ?? $code),
            );
        }

        return $events;
    }

    private function isNotFound(string $message): bool
    {
        $lower = mb_strtolower($message, 'UTF-8');

        return str_contains($lower, 'not found')
            || str_contains($lower, 'bulunamad')
            || str_contains($lower, 'no shipment')
            || str_contains($lower, 'no such')
            || str_contains($lower, 'does not exist');
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['ws_user_name', 'wsUserName', 'ws_password', 'wsPassword', 'username', 'password', 'token', 'authorization', 'secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
