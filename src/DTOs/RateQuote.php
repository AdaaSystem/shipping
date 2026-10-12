<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

final readonly class RateQuote
{
    public function __construct(
        public string $carrierCode,
        public string $serviceCode,
        public string $serviceName,
        public float $amount,
        public string $currency,
        public ?string $estimatedDelivery = null,
    ) {}
}
