<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ShipmentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $carrierCode,
        public readonly string $error,
        public readonly ?string $reference = null,
    ) {}
}
