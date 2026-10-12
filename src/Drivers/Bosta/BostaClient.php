<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Bosta;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP wrapper around the Bosta Business API v2.
 *
 * Auth is the raw API key as `Authorization: {api_key}` (never Bearer).
 * No facades, no global state, no caching — the caller caches per connection.
 */
final class BostaClient
{
    public const PRODUCTION_HOST = 'https://app.bosta.co';

    public const STAGING_HOST = 'https://stg-app.bosta.co';

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDelivery(array $payload): array
    {
        $response = $this->request()->post($this->path('deliveries'), $payload);

        return $this->data($response);
    }

    /**
     * Search / track by tracking number.
     *
     * @return array<string, mixed>
     */
    public function searchByTracking(string $trackingNumber): array
    {
        $response = $this->request()->post($this->path('deliveries/search'), [
            'trackingNumbers' => $trackingNumber,
        ]);

        return $this->data($response);
    }

    /**
     * Mass AWB PDF (base64) by tracking number.
     *
     * @return array<string, mixed>
     */
    public function massAwb(string $trackingNumber): array
    {
        $response = $this->request()->get($this->path('deliveries/mass-awb'), [
            'trackingNumbers' => $trackingNumber,
        ]);

        return $this->envelope($response);
    }

    /**
     * Single AWB by delivery id.
     *
     * @return array<string, mixed>
     */
    public function awb(string $deliveryId): array
    {
        $response = $this->request()->get($this->path('deliveries/awb/'.$deliveryId));

        return $this->envelope($response);
    }

    /**
     * Terminate / cancel a delivery.
     *
     * @return array<string, mixed>
     */
    public function terminate(string $deliveryIdOrTracking): array
    {
        $response = $this->request()->delete(
            $this->path('deliveries/business/'.$deliveryIdOrTracking.'/terminate')
        );

        return $this->envelope($response);
    }

    /**
     * List cities available for the business account.
     *
     * @return array<string, mixed>
     */
    public function cities(): array
    {
        $response = $this->request()->get($this->path('cities'));

        return $this->data($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function zones(string $cityId): array
    {
        $response = $this->request()->get($this->path('cities/'.$cityId.'/zones'));

        return $this->data($response);
    }

    private function request(): PendingRequest
    {
        $apiKey = $this->config['api_key'] ?? $this->config['token'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw ShippingException::carrierFailed('Missing Bosta API key.');
        }

        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->withHeaders([
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
                'X-Requested-By' => 'adaa-shipping-bosta',
            ]);
    }

    private function baseUrl(): string
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (is_string($baseUrl) && $baseUrl !== '') {
            $host = rtrim($baseUrl, '/');
        } else {
            $environment = $this->config['environment'] ?? 'production';

            if ($environment instanceof \BackedEnum) {
                $environment = $environment->value;
            }

            $host = strtolower((string) $environment) === 'sandbox' ? self::STAGING_HOST : self::PRODUCTION_HOST;
        }

        if (str_contains($host, '/api/')) {
            return $host;
        }

        $version = ltrim((string) ($this->config['api_version'] ?? 'v2'), '/');

        return $host.'/api/'.$version;
    }

    private function path(string $relative): string
    {
        $relative = ltrim($relative, '/');

        if ($relative === 'deliveries' && ($this->config['api_version'] ?? 'v2') === 'v2') {
            return 'deliveries?apiVersion=1';
        }

        return $relative;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $envelope = $this->envelope($response);
        $data = $envelope['data'] ?? $envelope;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if ($response->successful()) {
            if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                throw ShippingException::carrierFailed(
                    (string) ($json['message'] ?? 'Bosta request failed.'),
                    $response->status(),
                );
            }

            if (is_array($json) && $json !== []) {
                return $json;
            }

            $body = $response->body();

            if (is_string($body) && $body !== '') {
                if (str_starts_with(ltrim($body), '%PDF')) {
                    return ['success' => true, 'data' => base64_encode($body)];
                }

                return ['success' => true, 'data' => $body];
            }

            return is_array($json) ? $json : [];
        }

        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';

        if ($message === '') {
            $message = $response->body() !== '' ? $response->body() : 'Bosta request failed.';
        }

        throw ShippingException::carrierFailed($message, $response->status());
    }
}
