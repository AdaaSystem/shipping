<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Http\Controllers;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\HandlesWebhooks;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Laravel\Contracts\WebhookSecretResolver;
use Adaa\Shipping\Laravel\Events\WebhookReceived;
use Adaa\Shipping\Manager\ShippingManager;
use Adaa\Shipping\Support\WebhookSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Receives carrier status webhooks. Validity is enforced by signature
 * verification — the route itself carries no auth middleware.
 *
 * Drivers are resolved with an empty-credential context: verification
 * uses the per-connection secret (resolved by the host), never stored
 * carrier credentials. Normalize/track calls that need credentials
 * belong in the host's reconciler listening to WebhookReceived.
 */
class ShippingWebhookController extends Controller
{
    /**
     * @var list<string>
     */
    private const TRACKING_KEYS = [
        'tracking_number', 'tracking', 'trackingNumber', 'tracking_number_id',
        'consignment_id', 'shipment_id', 'parcel_id', 'awb', 'billCode', 'barcode',
    ];

    public function __invoke(Request $request, string $carrier, ShippingManager $manager): JsonResponse
    {
        $raw = (string) $request->getContent();
        $body = json_decode($raw, true);

        if (! is_array($body)) {
            return response()->json(['status' => 'ignored']);
        }

        $secret = $this->resolveSecret($carrier, $body);

        if ($secret === null && ! (bool) config('shipping.webhook.allow_unsigned', false)) {
            return response()->json(['status' => 'rejected'], 403);
        }

        try {
            $driver = $manager->driverForOrShared($this->verificationContext($carrier));
        } catch (ShippingException) {
            return response()->json(['status' => 'ignored']);
        }

        if ($secret !== null && ! $this->verify($driver, $request, $raw, $secret)) {
            Log::warning('Shipping webhook rejected: signature mismatch.', ['carrier' => $carrier]);

            return response()->json(['status' => 'rejected'], 403);
        }

        $status = $driver instanceof HandlesWebhooks
            ? $driver->normalizeWebhookStatus($body)
            : null;

        $tracking = $this->trackingFromPayload($body);

        if ($tracking === null) {
            return response()->json(['status' => 'ignored']);
        }

        WebhookReceived::dispatch($carrier, $tracking, $status, $body);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resolveSecret(string $carrier, array $body): ?string
    {
        $resolver = app()->bound(WebhookSecretResolver::class)
            ? app(WebhookSecretResolver::class)
            : null;

        if ($resolver === null) {
            return null;
        }

        $secret = $resolver->secretFor($carrier, $body);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function verificationContext(string $carrier): ShippingConnectionContext
    {
        return new ShippingConnectionContext(
            connectionId: 'webhook',
            carrierCode: $carrier,
            mode: ConnectionMode::Platform,
            environment: Environment::Production,
        );
    }

    private function verify(mixed $driver, Request $request, string $raw, string $secret): bool
    {
        if ($driver instanceof HandlesWebhooks) {
            return $driver->verifyWebhookSignature($request->headers->all(), $raw, $secret);
        }

        $candidate = WebhookSigner::fromHeaders($request->headers->all());

        return $candidate !== null && WebhookSigner::verify($candidate, $raw, $secret);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function trackingFromPayload(array $body): ?string
    {
        foreach (self::TRACKING_KEYS as $key) {
            $value = $body[$key] ?? data_get($body, 'data.'.$key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
