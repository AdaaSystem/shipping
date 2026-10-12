<?php

declare(strict_types=1);

namespace Adaa\Shipping\DTOs;

final readonly class ShipmentDraft
{
    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata  Carrier-specific fields (e.g. cod, city_code).
     */
    public function __construct(
        public Address $origin,
        public Address $destination,
        public array $parcels,
        public ?string $reference = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin->toArray(),
            'destination' => $this->destination->toArray(),
            'parcels' => array_map(static fn (Parcel $p): array => $p->toArray(), $this->parcels),
            'reference' => $this->reference,
            'metadata' => $this->metadata,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
