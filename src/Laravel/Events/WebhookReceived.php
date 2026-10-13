<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $carrierCode,
        public readonly ?string $trackingNumber,
        public readonly ?string $status,
        public readonly array $payload,
    ) {}
}
