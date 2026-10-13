<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mng;

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
 * Native MNG Kargo / DHL eCommerce Turkey driver (ApiZone REST).
 *
 * Supported (verified against the reference `mohamedhekal/shipbridge-mng`
 * source: `MngClient` endpoints + `MngDriver` flows + `config/mng.php`
 * status map): create (`createOrder` + `createbarcode`), track (Standard
 * Query status + movement history), cancel (`cancelorder` by reference id —
 * client-verified in the reference `MngClient`, though the reference driver
 * never surfaces it), return (`createReturnOrder`), exchange (forward
 * create), COD (`isCOD`/`codAmount`).
 *
 * Honestly disabled: label (ZPL exists only at `createbarcode` time and is
 * preserved in `raw.zpl`; no re-fetch endpoint — same as the reference,
 * whose `label()` throws), rates (no rates endpoint), pickup, webhooks,
 * customs, pickup points.
 *
 * Note the id split: `createShipment` returns `id` = carrier shipment id and
 * `trackingNumber` = scannable barcode. Pass the `raw.reference_id` value to
 * `cancel()`, which addresses Standard Command `cancelorder/{referenceId}`.
 */
final class MngDriver implements CarrierDriverInterface, SupportsCancellation, SupportsTracking
{
    private readonly MngPayloadFactory $payloads;

    public function __construct(
        private readonly MngClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new MngPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new MngClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * Shipment status codes 1–8 plus Turkish labels, per the reference
     * `config/mng.php` status map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            '1' => 'created',
            '2' => 'in_transit',
            '3' => 'in_transit',
            '4' => 'out_for_delivery',
            '5' => 'delivered',
            '6' => 'exception',
            '7' => 'returned',
            '8' => 'exception',
            'Gönderi Hazırlandı' => 'created',
            'Transfer Aşamasında' => 'in_transit',
            'Teslimat Birimine Ulaştı' => 'in_transit',
            'Alıcı Adresine Yönlendirildi' => 'out_for_delivery',
            'Teslim Edildi' => 'delivered',
            'Teslim Edilemedi' => 'exception',
            'Geri Geliyor' => 'returned',
            'Destek Gerekiyor' => 'exception',
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

        if ($built['shipment_kind'] === 'return') {
            $data = $this->client->createReturnOrder([
                'order' => $built['order']['order'],
                'orderPieceList' => $built['order']['orderPieceList'],
                'recipient' => $built['order']['recipient'],
            ]);

            $tracking = (string) ($data['returnOrderId'] ?? $data['shipmentId'] ?? $built['reference_id']);
            $url = isset($data['returnOrderLabelURL']) ? (string) $data['returnOrderLabelURL'] : null;

            return new ShipmentResult(
                id: $tracking,
                trackingNumber: $tracking,
                status: ShipmentStatus::Created,
                carrier: $connection->carrierCode,
                labelUrl: $url !== '' ? $url : null,
                raw: $this->sanitize([
                    'reference_id' => $built['reference_id'],
                    'response' => $data,
                ]),
            );
        }

        $order = $this->client->createOrder($built['order']);
        $barcode = null;

        if ($built['create_barcode'] && is_array($built['barcode'])) {
            $barcode = $this->client->createBarcode($built['barcode']);
        }

        return $this->toShipmentResult($order, $barcode, $built['reference_id'], $connection);
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $statusPayload = $this->client->getShipmentStatus($trackingNumber);
        $eventsPayload = [];

        try {
            $eventsPayload = $this->client->trackShipment($trackingNumber);
        } catch (ShippingException) {
            $eventsPayload = [];
        }

        $statusRaw = (string) ($statusPayload['shipmentStatusCode']
            ?? $statusPayload['shipmentStatus']
            ?? $statusPayload['status']
            ?? ShipmentStatus::InTransit->value);

        /** @var list<TrackingEvent> $events */
        $events = [];

        $rawEvents = $eventsPayload['shipmentTrackingList']
            ?? $eventsPayload['trackingList']
            ?? $eventsPayload['events']
            ?? (array_is_list($eventsPayload) ? $eventsPayload : []);

        foreach ((array) $rawEvents as $event) {
            if (! is_array($event)) {
                continue;
            }

            $code = (string) ($event['shipmentStatusCode'] ?? $event['statusCode'] ?? $event['status'] ?? $statusRaw);

            if ($code === '') {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($code),
                description: (string) ($event['description'] ?? $event['statusDescription'] ?? $event['event'] ?? $code),
                occurredAt: isset($event['eventDate']) ? (string) $event['eventDate'] : (isset($event['date']) ? (string) $event['date'] : null),
                location: isset($event['location']) ? (string) $event['location'] : (isset($event['branchName']) ? (string) $event['branchName'] : null),
            );
        }

        return new TrackingResult(
            trackingNumber: (string) ($statusPayload['shipmentId'] ?? $trackingNumber),
            status: $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'exception'),
            events: $events,
            raw: $this->sanitize(['status' => $statusPayload, 'events' => $eventsPayload]),
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $this->client->cancelOrder($shipmentId);

        return true;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>|null  $barcode
     */
    private function toShipmentResult(array $order, ?array $barcode, string $referenceId, ShippingConnectionContext $connection): ShipmentResult
    {
        $shipmentId = (string) ($order['shipmentId'] ?? $order['orderInvoiceId'] ?? $referenceId);

        if (is_array($barcode)) {
            $shipmentId = (string) ($barcode['shipmentId'] ?? $shipmentId);
        }

        $scannable = null;
        $zpl = null;

        if (is_array($barcode)) {
            $pieces = $barcode['barcodes'] ?? $barcode['orderPieceList'] ?? [];

            if (is_array($pieces) && isset($pieces[0]) && is_array($pieces[0])) {
                $scannable = isset($pieces[0]['barcode']) ? (string) $pieces[0]['barcode'] : null;
                $zpl = isset($pieces[0]['value']) ? (string) $pieces[0]['value'] : null;
            }
        }

        return new ShipmentResult(
            id: $shipmentId,
            trackingNumber: ($scannable !== null && $scannable !== '') ? $scannable : $shipmentId,
            status: $barcode !== null ? ShipmentStatus::Labeled : ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            raw: $this->sanitize([
                'reference_id' => $referenceId,
                'order' => $order,
                'barcode' => $barcode,
                'zpl' => $zpl,
                'scannable_barcode' => $scannable,
            ]),
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
        foreach (['client_id', 'client_secret', 'customer_number', 'password', 'jwt', 'token', 'authorization', 'secret', 'api_key', 'apikey', 'apiKey'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
