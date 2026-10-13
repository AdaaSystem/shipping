<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

/**
 * Opt-in webhook handling for drivers whose carriers push status
 * updates. Drivers without it receive a generic received-event only.
 */
interface HandlesWebhooks
{
    public function verifyWebhookSignature(array $headers, string $payload, string $secret): bool;

    /**
     * Normalize an incoming payload to a coarse status string, or null
     * when the event carries no status change worth persisting.
     */
    public function normalizeWebhookStatus(array $payload): ?string;
}
