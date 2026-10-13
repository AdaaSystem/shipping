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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $dimension = static function (string $key) use ($data): ?float {
            return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : null;
        };

        return new self(
            weightKg: (float) ($data['weight_kg'] ?? 0),
            description: isset($data['description']) ? (string) $data['description'] : null,
            quantity: max(1, (int) ($data['quantity'] ?? 1)),
            lengthCm: $dimension('length_cm'),
            widthCm: $dimension('width_cm'),
            heightCm: $dimension('height_cm'),
        );
    }
}
