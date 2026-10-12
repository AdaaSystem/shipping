<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

final readonly class Parcel
{
    public function __construct(
        public float $weightKg,
        public ?string $description = null,
        public int $quantity = 1,
        public ?float $lengthCm = null,
        public ?float $widthCm = null,
        public ?float $heightCm = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'weight_kg' => $this->weightKg,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
        ];
    }
}
