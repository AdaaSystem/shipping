<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\EgyptPost;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Egypt Post HTTP client (tracking-only).
 *
 * Tracking uses the official egyptpost.gov.eg TrackTrace endpoint.
 * Egypt Post publishes no public merchant create-shipment API, so this
 * client intentionally exposes no creation gateway.
 */
final class EgyptPostClient
{
    public const DEFAULT_TRACK_URL = 'https://egyptpost.gov.eg/ar-eg/TrackTrace/GetShipmentDetails';

    public const DEFAULT_TRACK_PORTAL_URL = 'https://egyptpost.gov.eg/ar-eg/TrackTrace?Barcode={barcode}';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Track via the official TrackTrace endpoint.
     *
     * @return array<string, mixed>
     */
    public function trackOfficial(string $barcode): array
    {
        $url = (string) ($this->config['track_url'] ?? self::DEFAULT_TRACK_URL);

        $response = $this->http
            ->timeout((int) ($this->config['timeout'] ?? 20))
            ->acceptJson()
            ->withHeaders([
                'X-Requested-By' => 'adaa-shipping-egyptpost',
                'User-Agent' => (string) ($this->config['user_agent'] ?? 'Adaa-Shipping-EgyptPost/1.0'),
            ])
            ->get($url, [
                'Barcode' => $barcode,
            ]);

        return $this->parseTrackResponse($response, $barcode);
    }

    public function publicTrackUrl(string $barcode): string
    {
        $template = (string) ($this->config['track_portal_url'] ?? self::DEFAULT_TRACK_PORTAL_URL);

        return str_replace('{barcode}', rawurlencode($barcode), $template);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTrackResponse(Response $response, string $barcode): array
    {
        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                'Egypt Post TrackTrace request failed.',
                $response->status(),
            );
        }

        $decoded = $this->decodeFlexible($response->body());

        if ($decoded === []) {
            throw ShippingException::carrierFailed('Egypt Post TrackTrace returned an unparseable response.');
        }

        $shipment = $this->extractShipmentNode($decoded);

        if ($shipment === []) {
            $shipment = $decoded;
        }

        $status = $this->pickString($shipment, [
            'Status', 'status', 'CurrentStatus', 'currentStatus', 'ShipmentStatus', 'shipmentStatus', 'LastStatus', 'lastStatus',
        ]);

        $resolvedBarcode = $this->pickString($shipment, [
            'Barcode', 'barcode', 'TrackingNumber', 'trackingNumber', 'TrackingNo', 'trackingNo',
        ]) ?? $barcode;

        /** @var list<array<string, mixed>> $rawEvents */
        $rawEvents = [];

        foreach ($this->eventSources($shipment, $decoded) as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $event) {
                if (is_array($event)) {
                    $rawEvents[] = $event;
                }
            }
        }

        /** @var list<array<string, mixed>> $events */
        $events = [];

        foreach ($rawEvents as $event) {
            $events[] = [
                'status' => $this->pickString($event, [
                    'Status', 'status', 'EventStatus', 'eventStatus', 'StatusDescription', 'statusDescription',
                ]) ?? $status ?? 'unknown',
                'description' => $this->pickString($event, [
                    'Description', 'description', 'Details', 'details', 'StatusDescription', 'statusDescription', 'Event', 'event', 'Remark', 'remark',
                ]) ?? '',
                'occurred_at' => $this->pickString($event, [
                    'Date', 'date', 'OccurredAt', 'occurredAt', 'EventDate', 'eventDate', 'Time', 'time', 'CreatedAt', 'createdAt',
                ]),
                'location' => $this->pickString($event, [
                    'Location', 'location', 'Office', 'office', 'Branch', 'branch', 'City', 'city',
                ]),
            ];
        }

        if ($events === [] && $status !== null) {
            $events[] = [
                'status' => $status,
                'description' => $this->pickString($shipment, ['StatusDescription', 'statusDescription', 'Message', 'message']) ?? $status,
                'occurred_at' => $this->pickString($shipment, ['LastUpdate', 'lastUpdate', 'UpdatedAt', 'updatedAt']),
                'location' => $this->pickString($shipment, ['Location', 'location', 'Office', 'office']),
            ];
        }

        return [
            'tracking_number' => $resolvedBarcode,
            'status' => $status ?? 'unknown',
            'events' => $events,
            'raw' => $decoded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFlexible(string $body): array
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return [];
        }

        $json = json_decode($trimmed, true);

        if (is_array($json)) {
            return $json;
        }

        if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches) === 1) {
            $embedded = json_decode($matches[0], true);

            if (is_array($embedded)) {
                return $embedded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function extractShipmentNode(array $decoded): array
    {
        foreach (['Data', 'data', 'Result', 'result', 'Shipment', 'shipment', 'ShipmentDetails', 'shipmentDetails'] as $key) {
            $node = $decoded[$key] ?? null;

            if (is_array($node)) {
                return $node;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>  $decoded
     * @return list<mixed>
     */
    private function eventSources(array $shipment, array $decoded): array
    {
        $keys = ['Events', 'events', 'History', 'history', 'TrackInfo', 'trackInfo', 'TrackingHistory', 'trackingHistory', 'Steps', 'steps'];
        $sources = [];

        foreach ($keys as $key) {
            if (isset($shipment[$key])) {
                $sources[] = $shipment[$key];
            }
        }

        if ($shipment !== $decoded) {
            foreach ($keys as $key) {
                if (isset($decoded[$key])) {
                    $sources[] = $decoded[$key];
                }
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function pickString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
