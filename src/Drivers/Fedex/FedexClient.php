<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Fedex;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP wrapper around the FedEx REST API.
 *
 * Live: https://apis.fedex.com · Sandbox: https://apis-sandbox.fedex.com
 *
 * Auth is OAuth2 client_credentials (POST /oauth/token as form) — the same
 * per-connection pattern as the sibling UpsClient: the token is memoized on
 * this instance only, and fromConnection() injects
 * $connection->cacheKey('fedex') as config token_cache_key, so two merchants
 * on the same carrier never share a token. No facades, no global state, no
 * framework cache — the caller owns any persistence keyed by that value. A
 * pre-issued bearer token via config['token'] skips the OAuth call (useful
 * for tests), mirroring the Mylerz access_token escape hatch.
 *
 * Endpoint shapes follow WhatArmy/FedexRest (most-adopted open-source
 * reference, ~57k packagist installs): /oauth/token, /ship/v1/shipments,
 * /rate/v1/rates/quotes, /track/v1/trackingnumbers, with labelSpecification
 * (imageType/labelStockType) and labelResponseOptions=LABEL. Response shapes
 * (output.transactionShipments[0].masterTrackingNumber,
 * output.completeTrackResults[0].trackResults[0].scanEvents[] with
 * derivedStatusCode) follow mohamedhekal/shipbridge-fedex. ShipStream's
 * Saloon-based SDK was not adopted (would add a Saloon dependency against
 * this package's illuminate/http contract), only its TokenCache idea.
 */
final class FedexClient
{
    public const PRODUCTION_HOST = 'https://apis.fedex.com';

    public const SANDBOX_HOST = 'https://apis-sandbox.fedex.com';

    private ?string $accessToken = null;

    private ?int $accessTokenExpiresAt = null;

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

    public static function clearTokenCache(): void
    {
        // No-op retained for test isolation helpers: tokens live per instance
        // (see tokenCacheKey()), so there is no global cache to clear.
    }

    /**
     * Per-connection token cache key (ShippingConnectionContext::cacheKey).
     * Informational: the token itself lives on this per-connection instance.
     */
    public function tokenCacheKey(): string
    {
        $key = $this->config['token_cache_key'] ?? $this->config['cache_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : 'shipping:token:default:fedex';
    }

    /**
     * Create a shipment (POST /ship/v1/shipments).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createShipment(array $payload): array
    {
        return $this->postJson('/ship/v1/shipments', $payload);
    }

    /**
     * Quote rates (POST /rate/v1/rates/quotes).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rateQuote(array $payload): array
    {
        return $this->postJson('/rate/v1/rates/quotes', $payload);
    }

    /**
     * Track by tracking number (POST /track/v1/trackingnumbers).
     *
     * @return array<string, mixed>
     */
    public function track(string $trackingNumber): array
    {
        return $this->postJson('/track/v1/trackingnumbers', [
            'includeDetailedScans' => true,
            'trackingInfo' => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => $trackingNumber,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload): array
    {
        $response = $this->authorizedRequest()->asJson()->post($path, $payload);

        return $this->decode($response);
    }

    private function authorizedRequest(): PendingRequest
    {
        return $this->request()->withToken($this->token());
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 60))
            ->acceptJson()
            ->withHeaders([
                'X-Requested-By' => 'adaa-shipping-fedex',
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

        if (strtolower((string) $environment) === 'sandbox') {
            return self::SANDBOX_HOST;
        }

        return self::PRODUCTION_HOST;
    }

    /**
     * OAuth2 client-credentials token, memoized per client (= per connection).
     *
     * A pre-issued bearer via config token/access_token skips the OAuth call,
     * mirroring the Mylerz access_token escape hatch for tests and token vaults.
     */
    public function token(): string
    {
        $configured = $this->config['token'] ?? $this->config['access_token'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->accessToken !== null && $this->accessTokenExpiresAt !== null && $this->accessTokenExpiresAt > time()) {
            return $this->accessToken;
        }

        $clientId = $this->config['client_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            throw ShippingException::carrierFailed('FedEx requires client_id (FEDEX_CLIENT_ID).');
        }

        $clientSecret = $this->config['client_secret'] ?? null;

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw ShippingException::carrierFailed('FedEx requires client_secret (FEDEX_CLIENT_SECRET).');
        }

        $response = $this->request()->asForm()->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        if (! $response->successful()) {
            $this->throwFromResponse($response, $json, 'FedEx OAuth token request failed.');
        }

        $accessToken = (string) ($json['access_token'] ?? '');

        if ($accessToken === '') {
            throw ShippingException::carrierFailed('FedEx OAuth returned no access_token.', $response->status());
        }

        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $expiresAt = time() + max(60, $expiresIn - 30);

        $this->accessToken = $accessToken;
        $this->accessTokenExpiresAt = $expiresAt;

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        if ($response->successful()) {
            return $json;
        }

        $this->throwFromResponse($response, $json, 'FedEx API request failed.');
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function throwFromResponse(Response $response, array $json, string $fallback): never
    {
        $message = $this->errorMessage($json, $response->body());

        throw ShippingException::carrierFailed($message !== '' ? $message : $fallback, $response->status());
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, string $body): string
    {
        $parts = [];

        if (isset($json['errors']) && is_array($json['errors'])) {
            foreach ($json['errors'] as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $code = isset($error['code']) ? (string) $error['code'] : '';
                $message = isset($error['message']) ? (string) $error['message'] : '';
                $combined = trim($code.($message !== '' ? ': '.$message : ''));

                if ($combined !== '') {
                    $parts[] = $combined;
                }
            }
        }

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        foreach (['message', 'error_description', 'error'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key]) && (string) $json[$key] !== '') {
                return (string) $json[$key];
            }
        }

        return $body;
    }
}
