<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

use Adaa\Shipping\Enums\ShipmentStatus;

final readonly class TrackingEvent
{
    public function __construct(
        public ShipmentStatus $status,
        public string $description = '',
        public ?string $occurredAt = null,
        public ?string $location = null,
    ) {}
}
