<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Dhl;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Random\RandomException;

/**
 * Thin HTTP wrapper around the DHL Express MyDHL REST API (spec 3.3.1).
 *
 * Auth is HTTP Basic (username + password) on every request — unanimous
 * across korbeil/dhl-express-php-api (BasicAuthAuthentication),
 * sonnenglas/mydhl-php-sdk (Guzzle `auth` => [username, password]) and
 * mohamedhekal/shipbridge-dhl (withBasicAuth). MyDHL Express exposes no
 * OAuth2 client_credentials flow and no API-key scheme, so there is no
 * token to cache: ShippingConnectionContext::cacheKey() is intentionally
 * unused and every request carries the connection's own credentials.
 *
 * Hosts: production https://express.api.dhl.com/mydhlapi, test
 * https://express.api.dhl.com/mydhlapi/test (sandbox, 500 calls/day).
 *
 * No facades, no global state, no caching.
 */
final class DhlClient
{
    public const PRODUCTION_HOST = 'https://express.api.dhl.com/mydhlapi';

    public const TEST_HOST = 'https://express.api.dhl.com/mydhlapi/test';

    public const API_VERSION = '3.3.1';

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
     * One-piece rates: GET /rates with query parameters.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getRates(array $query): array
    {
        return $this->decode($this->request()->get('rates', $query));
    }

    /**
     * Multi-piece rates: POST /rates with a JSON body (query parameters
     * cannot carry a list of packages).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postRates(array $payload): array
    {
        return $this->decode($this->request()->post('rates', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createShipment(array $payload): array
    {
        return $this->decode($this->request()->post('shipments', $payload));
    }

    /**
     * Track via the per-shipment endpoint, falling back to the batch
     * /tracking endpoint (same fallback as shipbridge-dhl).
     *
     * @return array<string, mixed>
     */
    public function trackShipment(string $trackingNumber): array
    {
        $encoded = rawurlencode($trackingNumber);

        try {
            return $this->decode($this->request()->get(
                "shipments/{$encoded}/tracking",
                [
                    'trackingView' => 'all-checkpoints',
                    'levelOfDetail' => 'all',
                    'requestGMTOffsetPerEvent' => 'true',
                ]
            ));
        } catch (ShippingException $primary) {
            try {
                return $this->decode($this->request()->get('tracking', [
                    'shipmentTrackingNumber' => $trackingNumber,
                    'trackingView' => 'all-checkpoints',
                    'levelOfDetail' => 'all',
                ]));
            } catch (ShippingException) {
                throw $primary;
            }
        }
    }

    private function request(): PendingRequest
    {
        $username = $this->config['username'] ?? $this->config['api_user'] ?? $this->config['user_name'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw ShippingException::carrierFailed(
                'Missing DHL credentials: username and password (HTTP Basic Auth).'
            );
        }

        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $password)
            ->withHeaders([
                'Message-Reference' => $this->messageReference(),
                'Message-Reference-Date' => gmdate('D, d M Y H:i:s \G\M\T'),
                'x-version' => self::API_VERSION,
                'X-Requested-By' => 'adaa-shipping-dhl',
            ]);
    }

    private function baseUrl(): string
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (is_string($baseUrl) && $baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        $environment = $this->config['environment'] ?? 'production';

        if ($environment instanceof \BackedEnum) {
            $environment = $environment->value;
        }

        $env = strtolower((string) $environment);

        if (in_array($env, ['sandbox', 'test', 'testing'], true)) {
            return self::TEST_HOST;
        }

        return self::PRODUCTION_HOST;
    }

    private function messageReference(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException) {
            return uniqid('dhl-', true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed>|list<mixed>|null $json */
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($response->successful()) {
            /** @var array<string, mixed> $payload */
            return $payload;
        }

        /** @var array<string, mixed> $payload */
        $message = $this->errorMessage($response, $payload);

        throw ShippingException::carrierFailed(
            $message !== '' ? $message : 'DHL MyDHL API request failed.',
            $response->status(),
        );
    }

    /**
     * DHL problem-details bodies carry title/detail/message plus an
     * additionalDetails list (one entry per rejected package).
     *
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(Response $response, array $json): string
    {
        $parts = [];

        foreach (['detail', 'title', 'message'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key]) && (string) $json[$key] !== '') {
                $parts[] = (string) $json[$key];
            }
        }

        if (isset($json['additionalDetails']) && is_array($json['additionalDetails'])) {
            foreach ($json['additionalDetails'] as $detail) {
                if (is_scalar($detail) && (string) $detail !== '') {
                    $parts[] = (string) $detail;
                }
            }
        }

        if ($parts === []) {
            $body = $response->body();

            if ($body !== '') {
                $parts[] = $body;
            }
        }

        if (($response->status() === 401 || $response->status() === 403) && $parts !== []) {
            array_unshift($parts, 'DHL authentication failed');
        }

        return implode(' — ', array_values(array_unique($parts)));
    }
}
