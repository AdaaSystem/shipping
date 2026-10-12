<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

use Adaa\Shipping\Enums\ShipmentStatus;

final readonly class ShipmentResult
{
    /**
     * @param  array<string, mixed>  $raw  Carrier response preserved for debugging (secrets must be redacted by the driver).
     */
    public function __construct(
        public string $id,
        public string $trackingNumber,
        public ShipmentStatus $status,
        public ?string $carrier = null,
        public ?string $labelUrl = null,
        public ?float $rate = null,
        public ?string $currency = null,
        public array $raw = [],
    ) {}
}
