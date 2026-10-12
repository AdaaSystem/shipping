<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\JtExpress;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP wrapper around the J&T Express Egypt Open Platform.
 *
 * Hosts (verified against genius-code/jt-express-eg + shipbridge-jtexpress):
 * live `https://openapi.jtjms-eg.com`, demo `https://demoopenapi.jtjms-eg.com`.
 *
 * Auth is per-connection and signature-based — no login/token flow exists:
 * every call posts form-encoded `bizContent=<json>` with headers `apiAccount`,
 * `digest = base64(md5(bizContentJson + privateKey))` and `timestamp`.
 * The JSON body itself carries a second `digest` derived from
 * `customerCode + customerPwd + privateKey` (raw mode), or — Egypt production
 * default — `customerCode + UPPERCASE(md5(customerPwd + "jadada236t2")) +
 * privateKey` per shipbridge-jtexpress `docs/API.md`.
 *
 * No facades, no global state, no caching — the caller caches per connection.
 */
final class JtExpressClient
{
    public const PRODUCTION_HOST = 'https://openapi.jtjms-eg.com';

    public const SANDBOX_HOST = 'https://demoopenapi.jtjms-eg.com';

    private const PASSWORD_SALT = 'jadada236t2';

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
     * Create an order (addOrder).
     *
     * @param  array<string, mixed>  $bizContent
     * @return array<string, mixed>
     */
    public function addOrder(array $bizContent): array
    {
        return $this->call('/webopenplatformapi/api/order/addOrder', $bizContent);
    }

    /**
     * Track by waybill (bill) code.
     *
     * @return array<string, mixed>
     */
    public function trace(string $billCode): array
    {
        return $this->call('/webopenplatformapi/api/logistics/trace', [
            'billCodes' => $billCode,
        ]);
    }

    /**
     * Print / fetch the waybill (label) for a waybill code.
     *
     * @return array<string, mixed>
     */
    public function printOrder(string $billCode, string $printSize = '0', int $printCode = 1): array
    {
        $biz = [
            'customerCode' => $this->customerCode(),
            'digest' => $this->bizDigest(),
            'billCode' => $billCode,
            'printSize' => $printSize,
            'printCode' => $printCode,
        ];

        $configured = $this->config['print_digest'] ?? null;

        if (is_string($configured) && $configured !== '') {
            $biz['digest'] = $configured;
        }

        return $this->call('/webopenplatformapi/api/order/printOrder', $biz);
    }

    /**
     * Cancel by merchant order id (txlogisticId), NOT the waybill code.
     *
     * @return array<string, mixed>
     */
    public function cancelOrder(string $txlogisticId, string $reason = 'Customer request'): array
    {
        return $this->call('/webopenplatformapi/api/order/cancelOrder', [
            'txlogisticId' => $txlogisticId,
            'orderType' => 1,
            'reason' => $reason,
            'customerCode' => $this->customerCode(),
            'digest' => $this->bizDigest(),
        ]);
    }

    /**
     * Inner biz-content digest (carried inside the JSON body).
     *
     * Egypt mode (default): UPPERCASE(md5(customerPwd + salt)) first.
     * Raw mode (`digest_mode=raw`): customerCode + customerPwd + privateKey,
     * matching genius-code/jt-express-eg.
     */
    public function bizDigest(): string
    {
        $customerCode = $this->customerCode();
        $password = (string) ($this->config['customer_pwd'] ?? $this->config['password'] ?? '');
        $privateKey = $this->privateKey();

        if (($this->config['digest_mode'] ?? 'egypt') === 'raw') {
            return base64_encode(md5($customerCode.$password.$privateKey, true));
        }

        $hashedPassword = strtoupper(md5($password.self::PASSWORD_SALT));

        return base64_encode(md5($customerCode.$hashedPassword.$privateKey, true));
    }

    /**
     * @param  array<string, mixed>  $bizContent
     * @return array<string, mixed>
     */
    private function call(string $path, array $bizContent): array
    {
        $bizJson = json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($bizJson === false) {
            throw ShippingException::carrierFailed('Failed to encode J&T bizContent JSON.');
        }

        $response = $this->request()
            ->withHeaders([
                'apiAccount' => $this->apiAccount(),
                'digest' => $this->headerDigest($bizJson),
                'timestamp' => $this->timestamp(),
            ])
            ->asForm()
            ->post($path, ['bizContent' => $bizJson]);

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->timeout((int) ($this->config['timeout'] ?? 60))
            ->acceptJson()
            ->withHeaders([
                'X-Requested-By' => 'adaa-shipping-jtexpress',
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

    private function headerDigest(string $bizContentJson): string
    {
        return base64_encode(md5($bizContentJson.$this->privateKey(), true));
    }

    private function timestamp(): string
    {
        if (($this->config['timestamp_mode'] ?? 'seconds') === 'millis') {
            return (string) (int) round(microtime(true) * 1000);
        }

        return (string) time();
    }

    private function apiAccount(): string
    {
        $value = $this->config['api_account'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing J&T api_account.');
        }

        return $value;
    }

    private function customerCode(): string
    {
        $value = $this->config['customer_code'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing J&T customer_code.');
        }

        return $value;
    }

    private function privateKey(): string
    {
        $value = $this->config['private_key'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing J&T private_key.');
        }

        return $value;
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

        $code = $json['code'] ?? null;
        $ok = $response->successful() && ((string) $code === '1' || $code === 1);

        if ($ok) {
            return $json;
        }

        $message = (string) ($json['msg'] ?? $json['message'] ?? '');

        if ($message === '') {
            $message = $response->body() !== '' ? $response->body() : 'J&T Express request failed.';
        }

        throw ShippingException::carrierFailed($message, $response->status());
    }
}
