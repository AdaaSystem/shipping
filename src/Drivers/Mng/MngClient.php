<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mng;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Raw REST client for the MNG Kargo / DHL eCommerce Turkey ApiZone stack.
 *
 * Auth is two-layer: IBM application headers (`x-ibm-client-id` /
 * `x-ibm-client-secret`) plus a per-merchant Bearer JWT from the Identity
 * API (`customerNumber` + panel `password`). The JWT is memoized per client
 * instance only — never in a facade, cache, or global state.
 *
 * Endpoints (path prefix `mngapi/api/...`, per `MngClient` in the reference
 * `mohamedhekal/shipbridge-mng` source): Identity `token`, Standard Command
 * `createOrder` / `cancelorder` / `createReturnOrder`, Standard Query
 * `getshipmentstatus*` / `trackshipment*`, Barcode Command `createbarcode` /
 * `cancelshipment`, Plus Command `createRecipient`, CBS Info
 * `getcities` / `getdistricts`.
 */
final class MngClient
{
    public const PRODUCTION_HOST = 'https://api.mngkargo.com.tr';

    public const SANDBOX_HOST = 'https://testapi.mngkargo.com.tr';

    private ?string $cachedJwt = null;

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
    public function createOrder(array $payload): array
    {
        return $this->unwrap($this->authenticated()->post($this->path('standardcmdapi/createOrder'), $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBarcode(array $payload): array
    {
        return $this->unwrap($this->authenticated()->post($this->path('barcodecmdapi/createbarcode'), $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createReturnOrder(array $payload): array
    {
        return $this->unwrap($this->authenticated()->post($this->path('standardcmdapi/createReturnOrder'), $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createRecipient(array $payload): array
    {
        return $this->unwrap($this->authenticated()->post($this->path('pluscmdapi/createRecipient'), $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function getShipmentStatus(string $idOrReference): array
    {
        $suffix = $this->isShipmentId($idOrReference)
            ? 'getshipmentstatusByShipmentId/'.$idOrReference
            : 'getshipmentstatus/'.$idOrReference;

        return $this->unwrap($this->authenticated()->get($this->path('standardqueryapi/'.$suffix)));
    }

    /**
     * @return array<string, mixed>
     */
    public function trackShipment(string $idOrReference): array
    {
        $suffix = $this->isShipmentId($idOrReference)
            ? 'trackshipmentByShipmentId/'.$idOrReference
            : 'trackshipment/'.$idOrReference;

        return $this->unwrap($this->authenticated()->get($this->path('standardqueryapi/'.$suffix)));
    }

    /**
     * Cancel a not-yet-dispatched order by its reference id.
     *
     * @return array<string, mixed>
     */
    public function cancelOrder(string $referenceId): array
    {
        return $this->unwrap($this->authenticated()->put($this->path('standardcmdapi/cancelorder/'.$referenceId)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancelShipment(array $payload): array
    {
        return $this->unwrap($this->authenticated()->put($this->path('barcodecmdapi/cancelshipment'), $payload));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCities(): array
    {
        return $this->unwrapList($this->ibmOnly()->get($this->path('cbsinfoapi/getcities')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDistricts(int $cityCode): array
    {
        return $this->unwrapList($this->ibmOnly()->get($this->path('cbsinfoapi/getdistricts/'.$cityCode)));
    }

    public function fetchJwt(bool $force = false): string
    {
        if (! $force && is_string($this->cachedJwt) && $this->cachedJwt !== '') {
            return $this->cachedJwt;
        }

        $customerNumber = (string) ($this->config['customer_number'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($customerNumber === '' || $password === '') {
            throw ShippingException::carrierFailed('Missing MNG customer_number or password (per-connection credentials).');
        }

        $response = $this->ibmOnly()->post($this->path('token'), [
            'customerNumber' => $customerNumber,
            'password' => $password,
            'identityType' => (int) ($this->config['identity_type'] ?? 1),
        ]);

        /** @var array<string, mixed>|list<mixed> $json */
        $json = $response->json() ?? [];

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                $this->safeMessage($this->errorMessage($json, $response->body())),
                $response->status(),
            );
        }

        $jwt = '';

        if (is_array($json)) {
            $jwt = (string) ($json['jwt'] ?? $json['token'] ?? '');

            if ($jwt === '' && isset($json[0]) && is_array($json[0])) {
                $jwt = (string) ($json[0]['jwt'] ?? $json[0]['token'] ?? '');
            }
        }

        if ($jwt === '') {
            throw ShippingException::carrierFailed('MNG Identity API returned an empty JWT.');
        }

        $this->cachedJwt = $jwt;

        return $jwt;
    }

    private function authenticated(): PendingRequest
    {
        return $this->ibmOnly()->withToken($this->fetchJwt());
    }

    private function ibmOnly(): PendingRequest
    {
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            throw ShippingException::carrierFailed('Missing MNG client_id or client_secret (per-connection credentials).');
        }

        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->withHeaders([
                'x-ibm-client-id' => $clientId,
                'x-ibm-client-secret' => $clientSecret,
                'Content-Type' => 'application/json',
                'X-Requested-By' => 'adaa-shipping-mng',
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

    private function path(string $relative): string
    {
        return 'mngapi/api/'.ltrim($relative, '/');
    }

    private function isShipmentId(string $value): bool
    {
        return preg_match('/^\d{10,}$/', $value) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        /** @var array<string, mixed>|list<mixed>|null $json */
        $json = $response->json();

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                $this->safeMessage($this->errorMessage(is_array($json) ? $json : [], $response->body())),
                $response->status(),
            );
        }

        if (! is_array($json)) {
            return [];
        }

        if (array_is_list($json) && isset($json[0]) && is_array($json[0])) {
            /** @var array<string, mixed> $first */
            $first = $json[0];

            return $first;
        }

        return $json;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unwrapList(Response $response): array
    {
        /** @var array<string, mixed>|list<mixed>|null $json */
        $json = $response->json();

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                $this->safeMessage($this->errorMessage(is_array($json) ? $json : [], $response->body())),
                $response->status(),
            );
        }

        if (! is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, 'is_array'));
        }

        $data = $json['data'] ?? [];

        return is_array($data) && array_is_list($data)
            ? array_values(array_filter($data, 'is_array'))
            : [];
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $json
     */
    private function errorMessage(array $json, string $fallback): string
    {
        if (isset($json['error']) && is_array($json['error'])) {
            return (string) ($json['error']['Description'] ?? $json['error']['description'] ?? $fallback);
        }

        if (isset($json[0]) && is_array($json[0]) && isset($json[0]['error']) && is_array($json[0]['error'])) {
            return (string) ($json[0]['error']['Description'] ?? $json[0]['error']['description'] ?? $fallback);
        }

        if (isset($json['message']) && is_string($json['message']) && $json['message'] !== '') {
            return $json['message'];
        }

        return $fallback !== '' ? $fallback : 'MNG request failed.';
    }

    /**
     * Scrub credential values out of any string that may reach an exception.
     */
    private function safeMessage(string $message): string
    {
        foreach (['client_secret', 'password'] as $key) {
            $secret = $this->config[$key] ?? null;

            if (is_string($secret) && $secret !== '' && str_contains($message, $secret)) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
