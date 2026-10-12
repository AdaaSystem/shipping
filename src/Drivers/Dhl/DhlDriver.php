<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Dhl;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Contracts\SupportsRates;
use Adaa\Shipping\Contracts\SupportsTracking;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\RateQuote;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\DTOs\ShipmentResult;
use Adaa\Shipping\DTOs\TrackingEvent;
use Adaa\Shipping\DTOs\TrackingResult;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native DHL Express carrier driver (Global, MyDHL REST API spec 3.3.1).
 *
 * Honestly scoped: rates (GET /rates one-piece, POST /rates multi-piece),
 * create (POST /shipments) and track (GET /shipments/{id}/tracking with
 * /tracking batch fallback). Everything else is disabled with evidence:
 * - label: NO dedicated endpoint exists. Per sonnenglas ImageService, the
 *   transport label is returned inline at creation only; /get-image
 *   re-downloads archived documents (waybill, commercial-invoice…) and
 *   needs shipperAccountNumber + pickupYearAndMonth, unavailable here.
 *   The inline label content is therefore preserved in ShipmentResult::$raw
 *   under `label_contents` (base64, as returned by DHL).
 * - cancel: MyDHL exposes pickup-booking cancel (by dispatch confirmation
 *   number) but no shipment cancel.
 * - return/exchange/COD: no DHL API construct found in any reference
 *   (no return endpoint, no COD service flag) — left false rather than
 *   synthesized.
 *
 * No SupportsLabels / SupportsCancellation: callers must read
 * capabilities() instead of catching unsupported-operation errors.
 */
final class DhlDriver implements CarrierDriverInterface, SupportsRates, SupportsTracking
{
    private readonly DhlPayloadFactory $payloads;

    public function __construct(
        private readonly DhlClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new DhlPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new DhlClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * DHL tracking typeCodes (PU/AR/DF/WC/DL/EX — see sonnenglas
     * TrackingEvent::TYPE_PICKED_UP) plus status-word fallbacks from the
     * shipbridge-dhl tracking map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'PU' => 'picked_up',
            'Picked Up' => 'picked_up',
            'pickup' => 'picked_up',
            'AR' => 'in_transit',
            'DF' => 'in_transit',
            'transit' => 'in_transit',
            'In Transit' => 'in_transit',
            'WC' => 'out_for_delivery',
            'With Delivery Courier' => 'out_for_delivery',
            'Out for Delivery' => 'out_for_delivery',
            'DL' => 'delivered',
            'Delivered' => 'delivered',
            'delivered' => 'delivered',
            'EX' => 'exception',
            'Exception' => 'exception',
            'Failed' => 'exception',
            'RT' => 'returned',
            'Returned' => 'returned',
            'CA' => 'cancelled',
            'Cancelled' => 'cancelled',
            'booked' => 'created',
            'Booked' => 'created',
            'created' => 'created',
        ];
    }

    public function capabilities(): CarrierCapabilities
    {
        return new CarrierCapabilities(
            rates: true,
            create: true,
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
        $response = $this->client->createShipment($this->payloads->create($draft));

        $trackingNumber = (string) ($response['shipmentTrackingNumber'] ?? '');

        if ($trackingNumber === '') {
            throw ShippingException::carrierFailed(
                'DHL create shipment returned no shipmentTrackingNumber.'
            );
        }

        $labelContents = $this->extractLabelContents($response);

        $raw = $this->sanitize([
            'shipment_tracking_number' => $trackingNumber,
            'dispatch_confirmation_number' => isset($response['dispatchConfirmationNumber']) ? (string) $response['dispatchConfirmationNumber'] : null,
            'tracking_url' => isset($response['trackingUrl']) && is_string($response['trackingUrl']) ? $response['trackingUrl'] : null,
            'has_label_contents' => $labelContents !== '',
            'label_contents' => $labelContents !== '' ? $labelContents : null,
            'warnings' => $response['warnings'] ?? [],
        ]);

        return new ShipmentResult(
            id: $trackingNumber,
            trackingNumber: $trackingNumber,
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            labelUrl: $raw['tracking_url'],
            raw: $raw,
        );
    }

    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array
    {
        $parcels = $draft->parcels === [] ? [new Parcel(weightKg: 1.0)] : $draft->parcels;

        $pieces = 0;

        foreach ($parcels as $parcel) {
            $pieces += max(1, $parcel->quantity);
        }

        $response = $pieces > 1
            ? $this->client->postRates($this->payloads->rateMulti($draft))
            : $this->client->getRates($this->payloads->rate($draft));

        $products = $response['products'] ?? [];

        if (! is_array($products)) {
            throw ShippingException::carrierFailed('DHL rates response had no products list.');
        }

        /** @var list<RateQuote> $quotes */
        $quotes = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            /** @var array<string, mixed> $product */
            $price = $this->billPrice($product);

            if ($price === null) {
                continue;
            }

            $delivery = $product['deliveryCapabilities'] ?? null;
            $estimated = is_array($delivery) && isset($delivery['estimatedDeliveryDateAndTime']) && is_string($delivery['estimatedDeliveryDateAndTime'])
                ? $delivery['estimatedDeliveryDateAndTime']
                : null;

            $quotes[] = new RateQuote(
                carrierCode: $connection->carrierCode,
                serviceCode: (string) ($product['productCode'] ?? ''),
                serviceName: (string) ($product['productName'] ?? $product['productCode'] ?? ''),
                amount: $price[0],
                currency: $price[1],
                estimatedDelivery: $estimated,
            );
        }

        if ($quotes === []) {
            throw ShippingException::carrierFailed('DHL rates returned no priced products.');
        }

        return $quotes;
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $response = $this->client->trackShipment($trackingNumber);

        /** @var array<string, mixed> $shipment */
        $shipment = [];

        if (isset($response['shipments']) && is_array($response['shipments'])) {
            $first = $response['shipments'][0] ?? null;

            if (is_array($first)) {
                /** @var array<string, mixed> $first */
                $shipment = $first;
            }
        } elseif ($response !== []) {
            /** @var array<string, mixed> $response */
            $shipment = $response;
        }

        if ($shipment === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ($this->shipmentEvents($shipment) as $event) {
            $code = (string) ($event['typeCode'] ?? $event['statusCode'] ?? $event['status'] ?? '');
            $description = (string) ($event['description'] ?? $event['remark'] ?? $code);

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($code !== '' ? $code : ($description !== '' ? $description : 'exception')),
                description: $description,
                occurredAt: $this->eventTimestamp($event),
                location: $this->eventLocation($event),
            );
        }

        $statusRaw = (string) ($shipment['status'] ?? $shipment['statusCode'] ?? '');

        if ($statusRaw === '' && $events !== []) {
            $first = $this->shipmentEvents($shipment)[0] ?? [];
            $statusRaw = (string) ($first['typeCode'] ?? $first['description'] ?? '');
        }

        return new TrackingResult(
            trackingNumber: (string) ($shipment['shipmentTrackingNumber'] ?? $trackingNumber),
            status: $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'exception'),
            events: $events,
            raw: $this->sanitize([
                'shipment_tracking_number' => (string) ($shipment['shipmentTrackingNumber'] ?? $trackingNumber),
                'status' => $statusRaw,
                'events' => $this->shipmentEvents($shipment),
            ]),
        );
    }

    /**
     * Inline transport label from the create-shipment documents array
     * (typeCode=label, imageFormat=PDF, content=base64 — see sonnenglas
     * ShipmentResponseParser::extractLabelPdf).
     *
     * @param  array<string, mixed>  $response
     */
    private function extractLabelContents(array $response): string
    {
        $documents = $response['documents'] ?? [];

        if (! is_array($documents)) {
            return '';
        }

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            $typeCode = strtoupper((string) ($document['typeCode'] ?? ''));
            $format = strtoupper((string) ($document['imageFormat'] ?? ($document['image_format'] ?? '')));

            if ($typeCode !== 'LABEL' || ($format !== '' && $format !== 'PDF')) {
                continue;
            }

            $content = (string) ($document['content'] ?? '');

            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    /**
     * BILLC (billing-currency) total price for one rated product — see
     * sonnenglas RateResponseParser::parseTotalPrice.
     *
     * @param  array<string, mixed>  $product
     * @return array{0: float, 1: string}|null
     */
    private function billPrice(array $product): ?array
    {
        $prices = $product['totalPrice'] ?? [];

        if (! is_array($prices)) {
            return null;
        }

        foreach ($prices as $entry) {
            if (! is_array($entry) || ($entry['currencyType'] ?? null) !== 'BILLC') {
                continue;
            }

            $price = $entry['price'] ?? null;

            if (! is_numeric($price)) {
                continue;
            }

            $currency = (string) ($entry['priceCurrency'] ?? '');

            return [(float) $price, $currency !== '' ? $currency : 'EUR'];
        }

        return null;
    }

    /**
     * Shipment-level scans plus piece-level scans (DHL does not always
     * mirror piece scans onto the shipment).
     *
     * @param  array<string, mixed>  $shipment
     * @return list<array<string, mixed>>
     */
    private function shipmentEvents(array $shipment): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = [];

        foreach ((array) ($shipment['events'] ?? []) as $event) {
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        foreach ((array) ($shipment['pieces'] ?? []) as $piece) {
            if (! is_array($piece) || ! isset($piece['events']) || ! is_array($piece['events'])) {
                continue;
            }

            foreach ($piece['events'] as $event) {
                if (is_array($event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventTimestamp(array $event): ?string
    {
        if (isset($event['timestamp']) && is_string($event['timestamp']) && $event['timestamp'] !== '') {
            return $event['timestamp'];
        }

        $date = (string) ($event['date'] ?? '');
        $time = (string) ($event['time'] ?? '');

        if ($date === '' && $time === '') {
            return null;
        }

        $offset = isset($event['GMTOffset']) && is_string($event['GMTOffset']) ? ' '.$event['GMTOffset'] : '';

        return trim($date.' '.$time.$offset) ?: null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventLocation(array $event): ?string
    {
        $area = $event['serviceArea'] ?? null;

        if (is_array($area)) {
            $first = $area[0] ?? $area;

            if (is_array($first)) {
                $location = (string) ($first['description'] ?? $first['code'] ?? '');

                if ($location !== '') {
                    return $location;
                }
            }
        }

        $location = isset($event['location']) ? (string) $event['location'] : '';

        return $location !== '' ? $location : null;
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['username', 'api_user', 'user_name', 'password', 'api_key', 'apikey', 'apiKey', 'token', 'access_token', 'authorization', 'secret', 'client_secret'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
