<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Fedex;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
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
 * Native FedEx REST carrier driver (Global).
 *
 * Reference choice: shipbridge-fedex (FedexDriver/FedexClient/PayloadFactory)
 * for the ShipBridge DTO mapping and the exact response shapes
 * (transactionShipments/masterTrackingNumber,
 * completeTrackResults/scanEvents/derivedStatusCode, packageDocuments), with
 * WhatArmy/FedexRest as the authoritative endpoint/field reference (OAuth
 * form, /ship|rate|track paths, labelSpecification, LABEL vs URL_ONLY). The
 * Saloon-based ShipStream SDK was inspected but not adopted as a runtime
 * dependency.
 *
 * Label gap fix: shipbridge-fedex embeds the PDF only in the create-response
 * raw (encodedLabel) while label() returns just the public tracking URL. This
 * driver preserves every packageDocuments encodedLabel (and url) in an
 * in-memory per-driver store at create time AND keeps the bytes in raw, so
 * label() reuses the stored bytes when available (base64Encoded=true) and
 * falls back to the public tracking URL with empty contents otherwise. The
 * store is per driver instance (built per connection via fromConnection), so
 * no label bytes leak across connections; persist raw.encodedLabel yourself
 * for durable access.
 *
 * Return/exchange travel through the same ship endpoint with swapped parties
 * (no dedicated FedEx return API in scope). Cancel, pickup, webhooks,
 * customs, and pickup points are honestly disabled: FedEx documents cancel/
 * pickup endpoints, but they are unverified without FedEx developer test keys
 * and are therefore never advertised.
 */
final class FedexDriver implements CarrierDriverInterface, SupportsLabels, SupportsRates, SupportsTracking
{
    private readonly FedexPayloadFactory $payloads;

    /**
     * @var array<string, string>
     */
    private array $labelBytes = [];

    /**
     * @var array<string, string|null>
     */
    private array $labelUrls = [];

    public function __construct(
        private readonly FedexClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new FedexPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;
        $config['token_cache_key'] = $connection->cacheKey('fedex');

        return new self(
            new FedexClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * Derived-code map from config/fedex.php in shipbridge-fedex (OC/PU/IT/
     * AR/DP/OD/DL/DE/RS/SE/CA) plus locale-string fallbacks.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'OC' => 'created',
            'PU' => 'picked_up',
            'IT' => 'in_transit',
            'AR' => 'in_transit',
            'DP' => 'in_transit',
            'OD' => 'out_for_delivery',
            'DL' => 'delivered',
            'DE' => 'delivered',
            'RS' => 'returned',
            'SE' => 'exception',
            'CA' => 'exception',
            'CREATED' => 'created',
            'PICKED UP' => 'picked_up',
            'PICKED_UP' => 'picked_up',
            'IN TRANSIT' => 'in_transit',
            'IN_TRANSIT' => 'in_transit',
            'OUT FOR DELIVERY' => 'out_for_delivery',
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
            rates: true,
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
        $response = $this->client->createShipment($this->payloads->create($draft));

        return $this->toShipmentResult($response, $connection, ShipmentStatus::Created);
    }

    public function createReturn(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $response = $this->client->createShipment($this->payloads->createReturn($draft));

        return $this->toShipmentResult($response, $connection, ShipmentStatus::Returned);
    }

    public function createExchange(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $response = $this->client->createShipment($this->payloads->createExchange($draft));

        return $this->toShipmentResult($response, $connection, ShipmentStatus::Exchanged);
    }

    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array
    {
        $response = $this->client->rateQuote($this->payloads->rate($draft));

        /** @var array<string, mixed> $output */
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];
        $details = $output['rateReplyDetails'] ?? [];

        if (! is_array($details) || $details === []) {
            throw ShippingException::carrierFailed('FedEx rate quote returned no rateReplyDetails.');
        }

        /** @var list<RateQuote> $quotes */
        $quotes = [];

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $serviceType = (string) ($detail['serviceType'] ?? '');

            if ($serviceType === '') {
                continue;
            }

            $charge = $this->extractRateCharge($detail);

            if ($charge === null) {
                continue;
            }

            $quotes[] = new RateQuote(
                carrierCode: $connection->carrierCode,
                serviceCode: $serviceType,
                serviceName: (string) ($detail['serviceName'] ?? $serviceType),
                amount: $charge['amount'],
                currency: $charge['currency'],
            );
        }

        if ($quotes === []) {
            throw ShippingException::carrierFailed('FedEx rate quote returned no usable charges.');
        }

        return $quotes;
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $response = $this->client->track($trackingNumber);

        /** @var array<string, mixed> $output */
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];

        /** @var array<string, mixed> $complete */
        $complete = is_array($output['completeTrackResults'][0] ?? null) ? $output['completeTrackResults'][0] : [];

        /** @var array<string, mixed> $trackResult */
        $trackResult = is_array($complete['trackResults'][0] ?? null) ? $complete['trackResults'][0] : [];

        if ($trackResult === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $statusRaw = $this->extractTrackStatus($trackResult);
        $status = $this->normalizer->normalize($statusRaw);

        /** @var list<TrackingEvent> $events */
        $events = [];
        $scans = $trackResult['scanEvents'] ?? [];

        if (is_array($scans)) {
            foreach ($scans as $scan) {
                if (! is_array($scan)) {
                    continue;
                }

                $eventStatus = (string) ($scan['derivedStatusCode'] ?? $scan['eventType'] ?? $scan['exceptionCode'] ?? $statusRaw);
                $location = null;

                if (isset($scan['scanLocation']) && is_array($scan['scanLocation'])) {
                    $city = (string) ($scan['scanLocation']['city'] ?? '');
                    $country = (string) ($scan['scanLocation']['countryName'] ?? '');
                    $location = $city !== '' ? $city : ($country !== '' ? $country : null);
                }

                $events[] = new TrackingEvent(
                    status: $this->normalizer->normalize($eventStatus !== '' ? $eventStatus : 'exception'),
                    description: (string) ($scan['eventDescription'] ?? $scan['exceptionDescription'] ?? $eventStatus),
                    occurredAt: isset($scan['date']) ? (string) $scan['date'] : null,
                    location: $location,
                );
            }
        }

        if ($events === [] && $statusRaw !== '') {
            $latest = $trackResult['latestStatusDetail'] ?? null;
            $location = null;

            if (is_array($latest) && isset($latest['scanLocation']) && is_array($latest['scanLocation'])) {
                $city = (string) ($latest['scanLocation']['city'] ?? '');

                if ($city !== '') {
                    $location = $city;
                }
            }

            $events[] = new TrackingEvent(
                status: $status,
                description: is_array($latest) ? (string) ($latest['description'] ?? $statusRaw) : $statusRaw,
                location: $location,
            );
        }

        $resolved = (string) (
            $trackResult['trackingNumberInfo']['trackingNumber']
            ?? $complete['trackingNumber']
            ?? $trackingNumber
        );

        return new TrackingResult(
            trackingNumber: $resolved,
            status: $status,
            events: $events,
            raw: $this->sanitize($response),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        if (isset($this->labelBytes[$shipmentId]) && $this->labelBytes[$shipmentId] !== '') {
            return new LabelResult(
                shipmentId: $shipmentId,
                format: $format,
                contents: $this->labelBytes[$shipmentId],
                base64Encoded: true,
                url: $this->labelUrls[$shipmentId] ?? null,
            );
        }

        $template = (string) ($this->client->config()['tracking_url_template'] ?? 'https://www.fedex.com/fedextrack/?trknbr={tracking}');
        $url = str_replace('{tracking}', rawurlencode($shipmentId), $template);

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: '',
            base64Encoded: false,
            url: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toShipmentResult(array $response, ShippingConnectionContext $connection, ShipmentStatus $status): ShipmentResult
    {
        /** @var array<string, mixed> $output */
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];

        /** @var array<string, mixed> $shipment */
        $shipment = is_array($output['transactionShipments'][0] ?? null) ? $output['transactionShipments'][0] : [];

        $masterTracking = (string) ($shipment['masterTrackingNumber'] ?? '');

        /** @var array<string, mixed> $piece */
        $piece = is_array($shipment['pieceResponses'][0] ?? null) ? $shipment['pieceResponses'][0] : [];
        $pieceTracking = (string) ($piece['trackingNumber'] ?? '');

        $trackingNumber = $masterTracking !== '' ? $masterTracking : $pieceTracking;

        if ($trackingNumber === '') {
            throw ShippingException::carrierFailed('FedEx createShipment returned no tracking number.');
        }

        $labelContents = '';
        $labelUrl = null;

        $documents = $piece['packageDocuments'] ?? [];

        if (is_array($documents)) {
            foreach ($documents as $document) {
                if (! is_array($document)) {
                    continue;
                }

                $encoded = (string) ($document['encodedLabel'] ?? $document['encodedLabelData'] ?? '');

                if ($encoded !== '' && $labelContents === '') {
                    $labelContents = $encoded;
                }

                $url = (string) ($document['url'] ?? '');

                if ($url !== '' && $labelUrl === null) {
                    $labelUrl = $url;
                }
            }
        }

        if ($labelContents !== '') {
            $this->labelBytes[$trackingNumber] = $labelContents;
            $this->labelUrls[$trackingNumber] = $labelUrl;
        }

        return new ShipmentResult(
            id: $trackingNumber,
            trackingNumber: $trackingNumber,
            status: $status,
            carrier: $connection->carrierCode,
            labelUrl: $labelUrl,
            raw: $this->sanitize($response),
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{amount: float, currency: string}|null
     */
    private function extractRateCharge(array $detail): ?array
    {
        $rated = $detail['ratedShipmentDetails'] ?? null;

        if (is_array($rated) && isset($rated[0]) && is_array($rated[0])) {
            $first = $rated[0];

            foreach (['totalNetCharge', 'shipmentRateDetail', 'totalNetFedExCharge'] as $key) {
                if ($key === 'shipmentRateDetail' && isset($first[$key]) && is_array($first[$key])) {
                    $inner = $first[$key];

                    if (isset($inner['totalNetCharge']) && is_array($inner['totalNetCharge'])) {
                        $parsed = $this->parseMoney($inner['totalNetCharge']);

                        if ($parsed !== null) {
                            return $parsed;
                        }
                    }
                }

                if ($key !== 'shipmentRateDetail' && isset($first[$key])) {
                    $value = $first[$key];

                    if (is_array($value)) {
                        $parsed = $this->parseMoney($value);

                        if ($parsed !== null) {
                            return $parsed;
                        }
                    }
                }
            }
        }

        foreach (['totalNetCharge', 'totalNetFedExCharge'] as $key) {
            if (isset($detail[$key]) && is_array($detail[$key])) {
                $parsed = $this->parseMoney($detail[$key]);

                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $money
     * @return array{amount: float, currency: string}|null
     */
    private function parseMoney(array $money): ?array
    {
        $amount = $money['amount'] ?? $money['value'] ?? null;
        $currency = (string) ($money['currency'] ?? $money['currencyCode'] ?? $money['currency_code'] ?? '');

        if (! is_numeric($amount) || $currency === '') {
            return null;
        }

        return ['amount' => (float) $amount, 'currency' => strtoupper($currency)];
    }

    /**
     * @param  array<string, mixed>  $trackResult
     */
    private function extractTrackStatus(array $trackResult): string
    {
        $latest = $trackResult['latestStatusDetail'] ?? null;

        if (is_array($latest)) {
            foreach (['code', 'derivedCode', 'statusByLocale', 'description'] as $key) {
                if (isset($latest[$key]) && is_scalar($latest[$key]) && (string) $latest[$key] !== '') {
                    return (string) $latest[$key];
                }
            }
        }

        return 'exception';
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     * Label bytes (encodedLabel) are intentionally preserved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['client_id', 'client_secret', 'clientId', 'clientSecret', 'api_key', 'apikey', 'apiKey', 'access_token', 'token', 'authorization', 'password', 'secret'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
