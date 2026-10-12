<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ups;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP wrapper around the UPS REST API (OAuth2 client-credentials).
 *
 * Auth decision: OAuth2 REST (POST /security/v1/oauth/token with Basic
 * client_id:client_secret, grant_type=client_credentials → Bearer
 * access_token) — NOT the legacy gabrielbull/php-ups-api AccessKey + UserID
 * + Password XML auth. UPS is migrating integrations to OAuth2 REST
 * (developer.ups.com; base hosts below), and the reference
 * mohamedhekal/shipbridge-ups driver proves Ship + Track over this exact
 * flow. Legacy XML auth is therefore intentionally unsupported here: any
 * AccessKey/UserID/Password credentials are ignored, and the migration risk
 * of staying on XML (deprecation, no new features) does not apply.
 *
 * Live: https://onlinetools.ups.com
 * CIE (sandbox): https://wwwcie.ups.com
 *
 * The OAuth token is memoized on this instance only. Drivers are built per
 * connection via fromConnection(), which injects
 * $connection->cacheKey('ups') as config token_cache_key, so two merchants
 * on the same carrier never share a token. No facades, no global state, no
 * framework cache — the caller owns any persistence keyed by that value.
 * This mirrors the sibling FedexClient OAuth2 pattern (per-instance
 * memoization with expires_in expiry + pre-issued token escape hatch);
 * unlike FedEx (form fields client_id/client_secret), UPS uses HTTP Basic
 * auth on the token call per the UPS OAuth spec.
 */
final class UpsClient
{
    public const PRODUCTION_HOST = 'https://onlinetools.ups.com';

    public const SANDBOX_HOST = 'https://wwwcie.ups.com';

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

    /**
     * Per-connection token cache key (ShippingConnectionContext::cacheKey).
     * Informational: the token itself lives on this per-connection instance.
     */
    public function tokenCacheKey(): string
    {
        $key = $this->config['token_cache_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : 'shipping:token:default:ups';
    }

    /**
     * Create a shipment + label (single Ship call, no confirm/accept split).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ship(array $payload): array
    {
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->post('/api/shipments/'.$this->version('shipment').'/ship', $payload);

        return $this->decode($response, 'UPS ship request failed.');
    }

    /**
     * Shop rates for all services (compare service levels/prices/transit).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function shopRates(array $payload): array
    {
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->post('/api/rating/'.$this->version('rating').'/Shop', $payload);

        return $this->decode($response, 'UPS rating request failed.');
    }

    /**
     * Track by tracking number (1Z…) or Mail Innovations id.
     *
     * @return array<string, mixed>
     */
    public function track(string $inquiryNumber): array
    {
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->get('/api/track/'.$this->version('track').'/details/'.rawurlencode($inquiryNumber));

        return $this->decode($response, 'UPS track request failed.');
    }

    /**
     * Void a shipment before it is tendered to UPS.
     *
     * DELETE /api/shipments/{version}/void/cancel/{shipmentId}[?trackingnumber=…]
     * Verified via the UPS OpenAPI surface (voidShipment operation), the UPS
     * Postman collection, and UPS-API/api-documentation issues #63/#108
     * (CIE examples voiding 1Z2220060294314162 + tracking
     * 1Z2220060291994175 → VoidShipmentResponse SummaryResult Voided).
     *
     * @return array<string, mixed>
     */
    public function voidShipment(string $shipmentIdentificationNumber, ?string $trackingNumber = null): array
    {
        $path = '/api/shipments/'.$this->version('shipment').'/void/cancel/'.rawurlencode($shipmentIdentificationNumber);

        if (is_string($trackingNumber) && $trackingNumber !== '') {
            $path .= '?trackingnumber='.rawurlencode($trackingNumber);
        }

        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->delete($path);

        return $this->decode($response, 'UPS void request failed.');
    }

    /**
     * Recover the label bytes for a previously created shipment.
     *
     * POST /api/labels/{version}/recovery — returns
     * LabelRecoveryResponse.LabelResults.LabelImage.GraphicImage (base64).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recoverLabel(array $payload): array
    {
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->post('/api/labels/'.$this->version('label').'/recovery', $payload);

        return $this->decode($response, 'UPS label recovery failed.');
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
        $clientSecret = $this->config['client_secret'] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw ShippingException::carrierFailed('Missing UPS client_id/client_secret (or pre-issued token).');
        }

        $response = $this->request()
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post('/security/v1/oauth/token', [
                'grant_type' => 'client_credentials',
            ]);

        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                $this->errorMessage($json, $response, 'UPS OAuth token request failed.'),
                $response->status(),
            );
        }

        $token = (string) ($json['access_token'] ?? '');

        if ($token === '') {
            throw ShippingException::carrierFailed('UPS OAuth response missing access_token.');
        }

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + max(60, ((int) ($json['expires_in'] ?? 3600)) - 30);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function transactionHeaders(): array
    {
        return [
            'transId' => bin2hex(random_bytes(16)),
            'transactionSrc' => (string) ($this->config['transaction_src'] ?? 'adaa-shipping-ups'),
        ];
    }

    private function authorized(): PendingRequest
    {
        return $this->request()->withToken($this->token());
    }

    private function request(): PendingRequest
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            $environment = $this->config['environment'] ?? 'production';

            if ($environment instanceof \BackedEnum) {
                $environment = $environment->value;
            }

            $baseUrl = strtolower((string) $environment) === 'sandbox' ? self::SANDBOX_HOST : self::PRODUCTION_HOST;
        }

        return $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Requested-By' => 'adaa-shipping-ups',
            ]);
    }

    private function version(string $scope): string
    {
        $key = $scope.'_version';

        $version = $this->config[$key] ?? $this->config['api_version'] ?? 'v1';

        return is_string($version) && $version !== '' ? ltrim($version, '/') : 'v1';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $fallback): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        if ($response->successful() && ! $this->hasErrors($json)) {
            return $json;
        }

        throw ShippingException::carrierFailed(
            $this->errorMessage($json, $response, $fallback),
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function hasErrors(array $json): bool
    {
        if (isset($json['response']['errors']) && is_array($json['response']['errors']) && $json['response']['errors'] !== []) {
            return true;
        }

        foreach (['ShipmentResponse', 'shipResponse', 'RateResponse', 'rateResponse', 'VoidShipmentResponse', 'LabelRecoveryResponse'] as $root) {
            if (! isset($json[$root]) || ! is_array($json[$root])) {
                continue;
            }

            /** @var array<string, mixed> $rootPayload */
            $rootPayload = $json[$root];

            $status = $rootPayload['Response']['ResponseStatus']['Code']
                ?? $rootPayload['response']['responseStatus']['code']
                ?? null;

            if ($status !== null && trim((string) $status) !== '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, Response $response, string $fallback): string
    {
        $errors = $json['response']['errors'] ?? $json['Response']['errors'] ?? null;

        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $message = (string) ($errors[0]['message'] ?? $errors[0]['code'] ?? '');

            if ($message !== '') {
                return $message;
            }
        }

        foreach (['ShipmentResponse', 'shipResponse', 'RateResponse', 'rateResponse', 'VoidShipmentResponse', 'LabelRecoveryResponse'] as $root) {
            if (! isset($json[$root]['Response']['ResponseStatus']) || ! is_array($json[$root]['Response']['ResponseStatus'])) {
                continue;
            }

            $description = trim((string) ($json[$root]['Response']['ResponseStatus']['Description'] ?? ''));

            if ($description !== '') {
                return $description;
            }
        }

        $message = (string) ($json['message'] ?? '');

        if ($message !== '') {
            return $message;
        }

        $body = $response->body();

        return $body !== '' ? $body : $fallback;
    }
}
