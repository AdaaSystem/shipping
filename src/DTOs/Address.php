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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            line1: (string) ($data['line1'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            countryCode: (string) ($data['country_code'] ?? ''),
            line2: isset($data['line2']) ? (string) $data['line2'] : null,
            state: isset($data['state']) ? (string) $data['state'] : null,
            postalCode: isset($data['postal_code']) ? (string) $data['postal_code'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            lat: isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null,
        );
    }
}
