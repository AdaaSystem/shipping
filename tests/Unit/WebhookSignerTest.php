<?php

declare(strict_types=1);

use Adaa\Shipping\Support\WebhookSigner;

test('signs and verifies plain hex signatures', function () {
    $signature = WebhookSigner::sign('{"a":1}', 'secret');

    expect(WebhookSigner::verify($signature, '{"a":1}', 'secret'))->toBeTrue()
        ->and(WebhookSigner::verify(strtoupper($signature), '{"a":1}', 'secret'))->toBeTrue()
        ->and(WebhookSigner::verify($signature, '{"a":1}', 'wrong'))->toBeFalse()
        ->and(WebhookSigner::verify($signature, '{"a":2}', 'secret'))->toBeFalse();
});

test('accepts sha256-prefixed and base64 signatures', function () {
    $hex = WebhookSigner::sign('payload', 'secret');

    expect(WebhookSigner::verify('sha256='.$hex, 'payload', 'secret'))->toBeTrue()
        ->and(WebhookSigner::verify(base64_encode((string) hex2bin($hex)), 'payload', 'secret'))->toBeTrue()
        ->and(WebhookSigner::verify('', 'payload', 'secret'))->toBeFalse()
        ->and(WebhookSigner::verify($hex, 'payload', ''))->toBeFalse();
});

test('extracts signatures from common headers', function () {
    expect(WebhookSigner::fromHeaders(['X-Signature' => ['abc']]))->toBe('abc')
        ->and(WebhookSigner::fromHeaders(['x-hub-signature-256' => 'sha256=def']))->toBe('sha256=def')
        ->and(WebhookSigner::fromHeaders([]))->toBeNull()
        ->and(WebhookSigner::fromHeaders(['X-Other' => 'x']))->toBeNull();
});
