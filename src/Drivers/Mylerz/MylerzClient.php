<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mylerz;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP wrapper around the Mylerz Integration API.
 *
 * Auth: POST /token (password grant) → Bearer access_token.
 * A pre-issued token via config access_token skips the /token call.
 * No facades, no global state, no caching — the caller caches per connection.
 */
final class MylerzClient
{
    public const DEFAULT_HOST = 'https://integration.mylerz.net';

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
     * Create orders via AddOrders.
     *
     * @param  list<array<string, mixed>>  $packages
     * @return array<string, mixed>
     */
    public function addOrders(array $packages): array
    {
        $response = $this->authed()->post('api/orders/addorders', $packages);

        return $this->value($response);
    }

    /**
     * Package status list for the given barcodes.
     *
     * @param  list<string>  $barcodes
     * @return array<int, mixed>
     */
    public function packageListStatus(array $barcodes): array
    {
        $response = $this->authed()->post('api/packages/GetPackageListStatus', $barcodes);

        return array_values($this->value($response));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAwb(string $barcode): array
    {
        $response = $this->authed()->post('api/packages/GetAWB', [
            'Barcode' => $barcode,
        ]);

        return $this->envelope($response);
    }

    private function authed(): PendingRequest
    {
        return $this->base()->withToken($this->accessToken(), 'Bearer');
    }

    private function base(): PendingRequest
    {
        $host = rtrim((string) ($this->config['base_url'] ?? self::DEFAULT_HOST), '/');

        return $this->http
            ->baseUrl($host)
            ->timeout((int) ($this->config['timeout'] ?? 45))
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Requested-By' => 'adaa-shipping-mylerz',
            ]);
    }

    private function accessToken(): string
    {
        $configured = $this->config['access_token'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (isset($this->config['token']) && is_string($this->config['token']) && $this->config['token'] !== '') {
            return $this->config['token'];
        }

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw ShippingException::carrierFailed('Missing Mylerz username/password (or access_token).');
        }

        $response = $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? self::DEFAULT_HOST), '/'))
            ->asForm()
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 45))
            ->post('token', [
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password',
            ]);

        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        $token = (string) ($json['access_token'] ?? '');

        if ($token === '') {
            throw ShippingException::carrierFailed(
                (string) ($json['error_description'] ?? $json['error'] ?? 'Mylerz token request failed.'),
                $response->status(),
            );
        }

        return $token;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function value(Response $response): array
    {
        $envelope = $this->envelope($response);
        $value = $envelope['Value'] ?? $envelope['value'] ?? $envelope;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        if (isset($json['Message']) && is_string($json['Message']) && str_contains($json['Message'], 'Authorization')) {
            throw ShippingException::carrierFailed($json['Message'], $response->status());
        }

        if (($json['IsErrorState'] ?? false) === true) {
            $error = $json['ErrorDescription'] ?? $json['Error'] ?? $json['Message'] ?? 'Mylerz request failed.';
            $message = is_string($error) ? $error : (json_encode($error) ?: 'Mylerz request failed.');

            throw ShippingException::carrierFailed($message, $response->status());
        }

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                (string) ($json['Message'] ?? $json['error_description'] ?? $response->body()),
                $response->status(),
            );
        }

        return $json;
    }
}
