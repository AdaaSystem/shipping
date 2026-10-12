<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

final readonly class Address
{
    public function __construct(
        public string $name,
        public string $line1,
        public string $city,
        public string $countryCode,
        public ?string $line2 = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'phone' => $this->phone,
            'email' => $this->email,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
