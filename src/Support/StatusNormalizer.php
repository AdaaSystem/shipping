<?php

declare(strict_types=1);

namespace Adaa\Shipping\Support;

use Adaa\Shipping\Enums\ShipmentStatus;

final class StatusNormalizer
{
    /**
     * @param  array<string, string>  $map  Raw carrier status => normalized value.
     */
    public function __construct(
        private readonly array $map = [],
    ) {}

    public function normalize(string $raw): ShipmentStatus
    {
        $key = strtolower(trim($raw));
        $key = str_replace(['-', ' '], '_', $key);

        $mapped = $this->map[$raw]
            ?? $this->map[$key]
            ?? $this->map[strtoupper($raw)]
            ?? $key;

        $value = is_string($mapped) ? strtolower(str_replace(['-', ' '], '_', $mapped)) : $key;

        return ShipmentStatus::tryFrom($value) ?? ShipmentStatus::Exception;
    }
}
