<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ups;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Contracts\SupportsRates;
use Adaa\Shipping\Contracts\SupportsTracking;
use Adaa\Shipping\DTOs\LabelResult;
use Adaa\Shipping\DTOs\RateQuote;
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
 * Native UPS carrier driver (Global, REST + OAuth2).
 *
 * Auth: OAuth2 client-credentials (see UpsClient). The legacy
 * gabrielbull/php-ups-api AccessKey+UserID+Password XML auth is NOT
 * supported — UPS is migrating to OAuth2 REST, and new integrations must
 * use it. Credentials named access_key/user_id/password are ignored.
 *
 * Supported (all verified against live API evidence, not just docs):
 * - create: POST /api/shipments/{v}/ship — one call returns
 *   ShipmentIdentificationNumber + per-package TrackingNumber + label bytes
 *   (PackageResults[].ShippingLabel.GraphicImage, base64). The bytes are
 *   preserved untouched in ShipmentResult::$raw — this fixes the
 *   shipbridge-ups label gap, where label() returned a tracking URL with
 *   empty contents.
 * - rates: POST /api/rating/{v}/Shop — one call returns all services as
 *   RateResponse.RatedShipment[] (Service Code/Description, TotalCharges,
 *   GuaranteedDelivery). Field shape verified via UPS-API/api-documentation
 *   Rating.yaml + UPS OpenAPI.
 * - track: GET /api/track/{v}/details/{id} — trackResponse.shipment[0].
 *   package[0].activity[] (+ currentStatus fallback), matching the
 *   shipbridge-ups traversal.
 * - label: POST /api/labels/{v}/recovery — returns real GraphicImage bytes
 *   (base64) + URL, verified via the UPS Postman collection + SimWorkflow
 *   operation docs.
 * - cancel: DELETE /api/shipments/{v}/void/cancel/{shipmentId} — void before
 *   tender, verified via UPS OpenAPI voidShipment + UPS-API/api-documentation
 *   issues #63/#108 (CIE void of 1Z2220060294314162 → SummaryResult Voided).
 * - COD via ShipmentServiceOptions (shipbridge-ups payload).
 *
 * Honestly disabled: return/exchange (no dedicated UPS return endpoint
 * verified; PRL return-service mapping needs live keys), pickup (separate
 * Pickup API, unscheduled here), webhooks (UPS uses Quantum View polling,
 * not per-connection webhooks in this integration), customs/paperless
 * (separate upload API), pickup points.
 */
final class UpsDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsRates, SupportsTracking
{
    private readonly UpsPayloadFactory $payloads;

    public function __construct(
        private readonly UpsClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new UpsPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;
        $config['token_cache_key'] = $connection->cacheKey('ups');

        return new self(
            new UpsClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * UPS tracking status codes (M/P/I/O/D/RS/X) plus human descriptions
     * from Track activity status.type/description, per the shipbridge-ups
     * status map and UPS tracking docs.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'M' => 'created',
            'P' => 'picked_up',
            'I' => 'in_transit',
            'O' => 'out_for_delivery',
            'D' => 'delivered',
            'RS' => 'returned',
            'X' => 'exception',
            'Billing Information Received' => 'created',
            'Manifest Pickup' => 'picked_up',
            'Pickup Scan' => 'picked_up',
            'Pickup' => 'picked_up',
            'Origin Scan' => 'in_transit',
            'Departure Scan' => 'in_transit',
            'Arrival Scan' => 'in_transit',
            'In Transit' => 'in_transit',
            'Out For Delivery' => 'out_for_delivery',
            'Delivered' => 'delivered',
            'Voided' => 'cancelled',
            'Cancelled' => 'cancelled',
            'Return to Sender' => 'returned',
            'Returned to Sender' => 'returned',
            'Returned' => 'returned',
            'Exception' => 'exception',
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
            rates: true,
            create: true,
            label: true,
            track: true,
            cancel: true,
            return: false,
            exchange: false,
            cod: true,
            webhooks: false,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $response = $this->client->ship($this->payloads->create($draft));

        /** @var array<string, mixed> $results */
        $results = $response['ShipmentResponse']['ShipmentResults']
            ?? $response['shipResponse']['shipmentResults']
            ?? [];

        if (! is_array($results) || $results === []) {
            throw ShippingException::carrierFailed('UPS ship response missing ShipmentResults.');
        }

        $shipmentId = trim((string) ($results['ShipmentIdentificationNumber'] ?? $results['shipmentIdentificationNumber'] ?? ''));

        $packageResults = $results['PackageResults'] ?? $results['packageResults'] ?? null;
        $firstPackage = $this->firstRow($packageResults);
        $trackingNumber = trim((string) ($firstPackage['TrackingNumber'] ?? $firstPackage['trackingNumber'] ?? ''));

        if ($shipmentId === '' && $trackingNumber === '') {
            throw ShippingException::carrierFailed('UPS ship response missing tracking number.');
        }

        $tracking = $trackingNumber !== '' ? $trackingNumber : $shipmentId;

        [$rate, $currency] = $this->shipmentCharges($results, $draft);

        return new ShipmentResult(
            id: $shipmentId !== '' ? $shipmentId : $tracking,
            trackingNumber: $tracking,
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            labelUrl: $this->trackingUrl($tracking),
            rate: $rate,
            currency: $currency,
            raw: $this->sanitize($response),
        );
    }

    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array
    {
        $response = $this->client->shopRates($this->payloads->rate($draft));

        /** @var array<string, mixed> $rateResponse */
        $rateResponse = $response['RateResponse'] ?? $response['rateResponse'] ?? [];

        if (! is_array($rateResponse)) {
            $rateResponse = [];
        }

        $rated = $rateResponse['RatedShipment'] ?? $rateResponse['ratedShipment'] ?? null;

        /** @var list<array<string, mixed>> $shipments */
        $shipments = $this->rows($rated);

        if ($shipments === []) {
            throw ShippingException::carrierFailed('UPS Shop response returned no RatedShipment.');
        }

        $negotiated = $this->isTruthy($draft->metadata['negotiated_rates'] ?? $this->client->config()['negotiated_rates'] ?? false);

        /** @var list<RateQuote> $quotes */
        $quotes = [];

        foreach ($shipments as $ratedShipment) {
            /** @var array<string, mixed> $service */
            $service = $ratedShipment['Service'] ?? $ratedShipment['service'] ?? [];
            $code = trim((string) ($service['Code'] ?? $service['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $charges = $negotiated
                ? ($ratedShipment['NegotiatedRateCharges'] ?? $ratedShipment['negotiatedRateCharges'] ?? $ratedShipment['TotalCharges'] ?? $ratedShipment['totalCharges'] ?? [])
                : ($ratedShipment['TotalCharges'] ?? $ratedShipment['totalCharges'] ?? []);

            if (! is_array($charges)) {
                $charges = [];
            }

            $amount = $charges['MonetaryValue'] ?? $charges['monetaryValue'] ?? null;

            if (! is_numeric($amount)) {
                continue;
            }

            $name = trim((string) ($service['Description'] ?? $service['description'] ?? ''))
                ?: (UpsPayloadFactory::SERVICE_NAMES[$code] ?? 'UPS '.$code);

            $quotes[] = new RateQuote(
                carrierCode: $connection->carrierCode,
                serviceCode: $code,
                serviceName: $name,
                amount: (float) $amount,
                currency: strtoupper(trim((string) ($charges['CurrencyCode'] ?? $charges['currencyCode'] ?? 'USD'))),
                estimatedDelivery: $this->estimatedDelivery($ratedShipment),
            );
        }

        if ($quotes === []) {
            throw ShippingException::carrierFailed('UPS Shop response had no priced services.');
        }

        return $quotes;
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        try {
            $response = $this->client->track($trackingNumber);
        } catch (ShippingException $exception) {
            if ($this->isNotFound($exception->getMessage())) {
                throw ShippingException::shipmentNotFound($trackingNumber);
            }

            throw $exception;
        }

        /** @var array<string, mixed> $trackResponse */
        $trackResponse = isset($response['trackResponse']) && is_array($response['trackResponse'])
            ? $response['trackResponse']
            : (isset($response['TrackResponse']) && is_array($response['TrackResponse']) ? $response['TrackResponse'] : $response);

        $shipments = $trackResponse['shipment'] ?? [];
        $shipment = $this->firstRow($shipments);

        if ($shipment === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $package = $this->firstRow($shipment['package'] ?? null);
        $resolvedTracking = trim((string) ($package['trackingNumber'] ?? $trackingNumber));

        /** @var list<TrackingEvent> $events */
        $events = [];

        $activities = $package['activity'] ?? null;

        foreach ($this->rows($activities) as $activity) {
            $statusRaw = $this->activityStatus($activity);
            $description = trim((string) ($activity['status']['description'] ?? $activity['status']['type'] ?? $statusRaw));

            $occurredAt = null;

            if (isset($activity['date']) || isset($activity['time'])) {
                $occurredAt = trim(((string) ($activity['date'] ?? '')).' '.((string) ($activity['time'] ?? '')));
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize($statusRaw !== '' ? $statusRaw : 'exception'),
                description: $description !== '' ? $description : $statusRaw,
                occurredAt: $occurredAt !== '' ? $occurredAt : null,
                location: $this->activityLocation($activity),
            );
        }

        if ($events !== []) {
            return new TrackingResult(
                trackingNumber: $resolvedTracking !== '' ? $resolvedTracking : $trackingNumber,
                status: $events[0]->status,
                events: $events,
                raw: $this->sanitize($response),
            );
        }

        $current = $package['currentStatus'] ?? null;

        if (is_array($current)) {
            $statusRaw = trim((string) ($current['code'] ?? $current['description'] ?? ''));

            if ($statusRaw !== '') {
                $status = $this->normalizer->normalize($statusRaw);

                return new TrackingResult(
                    trackingNumber: $resolvedTracking !== '' ? $resolvedTracking : $trackingNumber,
                    status: $status,
                    events: [
                        new TrackingEvent(
                            status: $status,
                            description: trim((string) ($current['description'] ?? $statusRaw)),
                        ),
                    ],
                    raw: $this->sanitize($response),
                );
            }
        }

        throw ShippingException::shipmentNotFound($trackingNumber);
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $imageFormat = $connection->metadata['label_image_format'] ?? strtoupper($format->value);

        $response = $this->client->recoverLabel($this->payloads->label($shipmentId, (string) $imageFormat));

        /** @var array<string, mixed> $recovery */
        $recovery = $response['LabelRecoveryResponse'] ?? $response['labelRecoveryResponse'] ?? [];

        if (! is_array($recovery)) {
            $recovery = [];
        }

        /** @var array<string, mixed> $labelResults */
        $labelResults = $recovery['LabelResults'] ?? $recovery['labelResults'] ?? [];

        if (! is_array($labelResults)) {
            $labelResults = [];
        }

        /** @var array<string, mixed> $labelImage */
        $labelImage = $labelResults['LabelImage'] ?? $labelResults['labelImage'] ?? [];

        if (! is_array($labelImage)) {
            $labelImage = [];
        }

        $contents = trim((string) ($labelImage['GraphicImage'] ?? $labelImage['graphicImage'] ?? ''));
        $url = isset($labelImage['URL']) ? trim((string) $labelImage['URL']) : (isset($labelImage['url']) ? trim((string) $labelImage['url']) : null);

        if ($contents === '' && ($url === null || $url === '')) {
            throw ShippingException::carrierFailed('UPS label recovery returned an empty label.');
        }

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: $contents !== '' && ! str_starts_with($contents, '%PDF'),
            url: $url !== '' ? $url : null,
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $trackingNumber = $connection->metadata['tracking_number'] ?? null;

        $response = $this->client->voidShipment(
            $shipmentId,
            is_string($trackingNumber) && $trackingNumber !== '' ? $trackingNumber : null,
        );

        /** @var array<string, mixed> $void */
        $void = $response['VoidShipmentResponse'] ?? $response['voidShipmentResponse'] ?? [];

        if (! is_array($void)) {
            $void = [];
        }

        /** @var array<string, mixed> $summary */
        $summary = $void['SummaryResult'] ?? $void['summaryResult'] ?? [];

        if (! is_array($summary)) {
            $summary = [];
        }

        /** @var array<string, mixed> $status */
        $status = $summary['Status'] ?? $summary['status'] ?? [];

        if (! is_array($status)) {
            $status = [];
        }

        $code = trim((string) ($status['Code'] ?? $status['code'] ?? ''));
        $description = strtolower(trim((string) ($status['Description'] ?? $status['description'] ?? '')));

        if ($code === '1' || str_contains($description, 'void')) {
            return true;
        }

        throw ShippingException::carrierFailed('UPS void response did not confirm cancellation.');
    }

    /**
     * Label bytes (base64 GraphicImage) from a ship or label-recovery raw
     * payload. ShipmentResult::$raw preserves the full response, so consumers
     * that stored raw can extract the label without a second API call.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function extractLabelContents(array $raw): ?string
    {
        $results = $raw['ShipmentResponse']['ShipmentResults']
            ?? $raw['shipResponse']['shipmentResults']
            ?? null;

        if (is_array($results)) {
            $packageResults = $results['PackageResults'] ?? $results['packageResults'] ?? null;

            foreach (self::rowsStatic($packageResults) as $row) {
                $label = $row['ShippingLabel'] ?? $row['shippingLabel'] ?? null;

                if (! is_array($label)) {
                    continue;
                }

                $graphic = trim((string) ($label['GraphicImage'] ?? $label['graphicImage'] ?? ''));

                if ($graphic !== '') {
                    return $graphic;
                }
            }
        }

        $recovery = $raw['LabelRecoveryResponse'] ?? $raw['labelRecoveryResponse'] ?? null;

        if (is_array($recovery)) {
            $labelResults = $recovery['LabelResults'] ?? $recovery['labelResults'] ?? [];

            if (is_array($labelResults)) {
                $labelImage = $labelResults['LabelImage'] ?? $labelResults['labelImage'] ?? [];

                if (is_array($labelImage)) {
                    $graphic = trim((string) ($labelImage['GraphicImage'] ?? $labelImage['graphicImage'] ?? ''));

                    if ($graphic !== '') {
                        return $graphic;
                    }
                }
            }
        }

        return null;
    }

    /**
     * ShipmentCharges (prefer negotiated when requested) → [rate, currency].
     *
     * @param  array<string, mixed>  $results
     * @return array{0: ?float, 1: ?string}
     */
    private function shipmentCharges(array $results, ShipmentDraft $draft): array
    {
        /** @var array<string, mixed> $charges */
        $charges = $results['ShipmentCharges'] ?? $results['shipmentCharges'] ?? [];

        if (! is_array($charges)) {
            return [null, null];
        }

        $negotiated = $this->isTruthy($draft->metadata['negotiated_rates'] ?? $this->client->config()['negotiated_rates'] ?? false);

        $preferred = $negotiated
            ? ($charges['NegotiatedRateCharges'] ?? $charges['negotiatedRateCharges'] ?? $charges['TotalCharges'] ?? $charges['totalCharges'] ?? [])
            : ($charges['TotalCharges'] ?? $charges['totalCharges'] ?? []);

        if (! is_array($preferred)) {
            return [null, null];
        }

        $amount = $preferred['MonetaryValue'] ?? $preferred['monetaryValue'] ?? null;

        if (! is_numeric($amount)) {
            return [null, null];
        }

        $currency = $preferred['CurrencyCode'] ?? $preferred['currencyCode'] ?? null;

        return [(float) $amount, is_string($currency) && $currency !== '' ? strtoupper(trim($currency)) : null];
    }

    private function trackingUrl(string $tracking): string
    {
        $template = $this->client->config()['tracking_url_template']
            ?? 'https://www.ups.com/track?tracknum={tracking}';

        return str_replace('{tracking}', rawurlencode($tracking), (string) $template);
    }

    /**
     * @param  array<string, mixed>  $ratedShipment
     */
    private function estimatedDelivery(array $ratedShipment): ?string
    {
        /** @var array<string, mixed> $guaranteed */
        $guaranteed = $ratedShipment['GuaranteedDelivery'] ?? $ratedShipment['guaranteedDelivery'] ?? [];

        if (! is_array($guaranteed)) {
            return null;
        }

        $days = trim((string) ($guaranteed['BusinessDaysInTransit'] ?? $guaranteed['businessDaysInTransit'] ?? ''));

        if ($days === '') {
            return null;
        }

        $byTime = trim((string) ($guaranteed['DeliveryByTime'] ?? $guaranteed['deliveryByTime'] ?? ''));

        return $byTime !== '' ? $days.' business days by '.$byTime : $days.' business days';
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityStatus(array $activity): string
    {
        if (isset($activity['status']) && is_array($activity['status'])) {
            $status = $activity['status'];

            return (string) ($status['type'] ?? $status['code'] ?? $status['description'] ?? 'in_transit');
        }

        return (string) ($activity['status'] ?? 'in_transit');
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityLocation(array $activity): ?string
    {
        $address = $activity['location']['address'] ?? null;

        if (! is_array($address)) {
            return isset($activity['location']) && is_string($activity['location']) ? $activity['location'] : null;
        }

        $parts = array_filter([
            isset($address['city']) ? trim((string) $address['city']) : null,
            isset($address['stateProvince']) ? trim((string) $address['stateProvince']) : null,
            isset($address['countryCode']) ? trim((string) $address['countryCode']) : null,
        ]);

        $location = implode(', ', array_filter($parts));

        return $location !== '' ? $location : null;
    }

    private function isNotFound(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not found')
            || str_contains($lower, 'no tracking')
            || str_contains($lower, 'no shipment')
            || str_contains($lower, 'no such')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'invalid tracking')
            || str_contains($lower, 'invalid inquiry');
    }

    /**
     * First row of a list-or-single response node.
     *
     * @return array<string, mixed>
     */
    private function firstRow(mixed $node): array
    {
        $rows = $this->rows($node);

        return $rows[0] ?? [];
    }

    /**
     * Normalize a list-or-single response node to a list of rows.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        if ($node === []) {
            return [];
        }

        if (array_is_list($node)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values(array_filter($node, static fn (mixed $row): bool => is_array($row)));

            return $rows;
        }

        /** @var array<string, mixed> $node */
        return [$node];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsStatic(mixed $node): array
    {
        if (! is_array($node) || $node === []) {
            return [];
        }

        if (array_is_list($node)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values(array_filter($node, static fn (mixed $row): bool => is_array($row)));

            return $rows;
        }

        /** @var array<string, mixed> $node */
        return [$node];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'y', 'yes', 'true', 'x'], true);
        }

        return (bool) $value;
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     * Label bytes (GraphicImage/HTMLImage) and tracking numbers are data, not
     * secrets, and are intentionally preserved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['client_id', 'client_secret', 'token', 'access_token', 'authorization', 'password', 'secret', 'account_number', 'shipper_number', 'AccountNumber', 'ShipperNumber'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
