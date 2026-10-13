<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Events;

use Adaa\Shipping\DTOs\ShipmentResult;
use Illuminate\Foundation\Events\Dispatchable;

class ShipmentCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $carrierCode,
        public readonly ShipmentResult $shipment,
    ) {}
}
