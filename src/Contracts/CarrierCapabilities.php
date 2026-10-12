<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

/**
 * Declared abilities of one driver. Only truly supported operations
 * may be set; the marketplace UI reads this to decide what to offer.
 */
final readonly class CarrierCapabilities
{
    public function __construct(
        public bool $rates = false,
        public bool $create = true,
        public bool $label = false,
        public bool $track = false,
        public bool $cancel = false,
        public bool $pickup = false,
        public bool $return = false,
        public bool $exchange = false,
        public bool $cod = false,
        public bool $webhooks = false,
        public bool $customs = false,
        public bool $pickupPoints = false,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'create' => $this->create,
            'label' => $this->label,
            'track' => $this->track,
            'cancel' => $this->cancel,
            'pickup' => $this->pickup,
            'return' => $this->return,
            'exchange' => $this->exchange,
            'cod' => $this->cod,
            'webhooks' => $this->webhooks,
            'customs' => $this->customs,
            'pickup_points' => $this->pickupPoints,
        ];
    }
}
