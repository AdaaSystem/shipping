<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Contracts;

/**
 * Host-bound resolver: given a carrier code (and the webhook payload),
 * return that connection's shared webhook secret, or null when the
 * carrier is unknown. The controller rejects unsigned/unknown calls
 * unless the host explicitly allows unsigned webhooks in config.
 */
interface WebhookSecretResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function secretFor(string $carrierCode, array $payload): ?string;
}
