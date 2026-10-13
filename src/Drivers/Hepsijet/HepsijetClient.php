<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Hepsijet;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Raw REST client for HepsiJet (Hepsiburada Logistics) Turkey.
 *
 * Auth is `GET /auth/getToken` with HTTP Basic (`username`/`password`),
 * then `X-Auth-Token` on every call (60-minute TTL per the reference
 * `mohamedhekal/shipbridge-hepsijet` `HepsiJetClient` source). The token is
 * memoized per client instance only — never in a facade, cache, or global
 * state.
 *
 * Calls (same paths as the reference client): `POST
 * /rest/delivery/sendDeliveryOrderEnhanced` (create), `POST
 * /rest/delivery/integration/track` (track), `POST
 * /delivery/barcodes-label?format=PDF` (label).
 */
final class HepsijetClient
{
    public const PRODUCTION_HOST = 'https://integration.hepsijet.com';

    public const SANDBOX_HOST = 'https://integration-apitest.hepsijet.com';

    private ?string $cachedToken = null;

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
    public function sendDeliveryOrder(array $payload): array
    {
        $response = $this->authenticated()->post('/rest/delivery/sendDeliveryOrderEnhanced', $payload);

        /** @var array<string, mixed> $data */
        $data = $this->unwrap($response);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    public function track(array $payload): array
    {
        $response = $this->authenticated()->post('/rest/delivery/integration/track', $payload);

        return $this->unwrap($response);
    }

    /**
     * @param  list<string>  $barcodes
     * @return array<string, mixed>|list<mixed>
     */
    public function fetchLabel(array $barcodes, string $format = 'PDF'): array
    {
        $response = $this->authenticated()->post(
            '/delivery/barcodes-label?format='.rawurlencode($format),
            ['barcodes' => $barcodes],
        );

        return $this->unwrap($response);
    }

    public function fetchToken(bool $force = false): string
    {
        if (! $force && is_string($this->cachedToken) && $this->cachedToken !== '') {
            return $this->cachedToken;
        }

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw ShippingException::carrierFailed('Missing HepsiJet username or password (per-connection credentials).');
        }

        $response = $this->baseRequest()->withBasicAuth($username, $password)->get('/auth/getToken');

        /** @var array<string, mixed>|list<mixed>|null $json */
        $json = $response->json();

        if (! $response->successful() || (is_array($json) && ($json['status'] ?? '') !== 'OK')) {
            $message = is_array($json) && isset($json['message']) && is_string($json['message'])
                ? $json['message']
                : $response->body();

            throw ShippingException::carrierFailed(
                $message !== '' ? $message : 'HepsiJet auth failed.',
                $response->status(),
            );
        }

        $token = '';

        if (is_array($json)) {
            $data = $json['data'] ?? null;
            $token = (string) (is_array($data) ? ($data['token'] ?? '') : ($json['token'] ?? ''));
        }

        if ($token === '') {
            throw ShippingException::carrierFailed('HepsiJet auth returned an empty token.');
        }

        $this->cachedToken = $token;

        return $token;
    }

    private function authenticated(): PendingRequest
    {
        return $this->baseRequest()->withHeaders([
            'X-Auth-Token' => $this->fetchToken(),
        ]);
    }

    private function baseRequest(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-By' => 'adaa-shipping-hepsijet',
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

        return strtolower((string) $environment) === 'sandbox' ? self::SANDBOX_HOST : self::PRODUCTION_HOST;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function unwrap(Response $response): array
    {
        /** @var array<string, mixed>|list<mixed>|null $json */
        $json = $response->json();

        if (! $response->successful()) {
            $message = is_array($json) && isset($json['message']) && is_string($json['message'])
                ? $json['message']
                : $response->body();

            throw ShippingException::carrierFailed(
                $message !== '' ? $message : 'HepsiJet request failed.',
                $response->status(),
            );
        }

        if (is_array($json) && isset($json['status']) && $json['status'] !== 'OK') {
            throw ShippingException::carrierFailed(
                (string) ($json['message'] ?? 'HepsiJet request failed.'),
                $response->status(),
            );
        }

        if (! is_array($json)) {
            return [];
        }

        $data = $json['data'] ?? $json;

        return is_array($data) ? $data : [];
    }
}
