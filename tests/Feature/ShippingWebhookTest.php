<?php

declare(strict_types=1);

use Adaa\Shipping\Laravel\Contracts\WebhookSecretResolver;
use Adaa\Shipping\Laravel\Events\WebhookReceived;
use Adaa\Shipping\Support\WebhookSigner;
use Illuminate\Support\Facades\Event;

class TestWebhookSecretResolver implements WebhookSecretResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function secretFor(string $carrierCode, array $payload): ?string
    {
        return $carrierCode === 'fake' ? 'webhook-secret' : null;
    }
}

beforeEach(function () {
    app()->bind(WebhookSecretResolver::class, TestWebhookSecretResolver::class);
});

function signedWebhookPayload(): array
{
    return ['tracking_number' => 'SHP00000001', 'status' => 'delivered'];
}

test('signed webhook is accepted and dispatched', function () {
    Event::fake([WebhookReceived::class]);

    $body = signedWebhookPayload();
    $raw = json_encode($body);

    $this->postJson(
        '/webhooks/shipping/fake',
        $body,
        ['X-Signature' => WebhookSigner::sign((string) $raw, 'webhook-secret')]
    )->assertOk()->assertJson(['status' => 'ok']);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->carrierCode === 'fake'
        && $event->trackingNumber === 'SHP00000001');
});

test('forged signature is rejected without dispatch', function () {
    Event::fake([WebhookReceived::class]);

    $this->postJson('/webhooks/shipping/fake', signedWebhookPayload(), ['X-Signature' => 'forged'])
        ->assertForbidden()->assertJson(['status' => 'rejected']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('unknown carrier is rejected without dispatch', function () {
    Event::fake([WebhookReceived::class]);

    // No secret resolves for an unknown carrier, so the default
    // deny-unsigned policy rejects the call instead of ignoring it.
    $this->postJson('/webhooks/shipping/nope', signedWebhookPayload(), ['X-Signature' => 'x'])
        ->assertForbidden()->assertJson(['status' => 'rejected']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('payload without tracking is ignored', function () {
    Event::fake([WebhookReceived::class]);

    $body = ['status' => 'delivered'];
    $raw = (string) json_encode($body);

    $this->postJson(
        '/webhooks/shipping/fake',
        $body,
        ['X-Signature' => WebhookSigner::sign($raw, 'webhook-secret')]
    )->assertOk()->assertJson(['status' => 'ignored']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('unsigned webhooks pass only when explicitly allowed', function () {
    Event::fake([WebhookReceived::class]);

    // A bound resolver returning no secret + default policy rejects.
    app()->instance(WebhookSecretResolver::class, new class implements WebhookSecretResolver
    {
        public function secretFor(string $carrierCode, array $payload): ?string
        {
            return null;
        }
    });

    $this->postJson('/webhooks/shipping/fake', signedWebhookPayload())
        ->assertForbidden();

    config()->set('shipping.webhook.allow_unsigned', true);

    $this->postJson('/webhooks/shipping/fake', signedWebhookPayload())
        ->assertOk()->assertJson(['status' => 'ok']);
});
