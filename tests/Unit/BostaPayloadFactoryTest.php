<?php

declare(strict_types=1);

use Adaa\Shipping\Drivers\Bosta\BostaPayloadFactory;
use Adaa\Shipping\Drivers\Bosta\DeliveryType;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;

function bostaUnitDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt')],
        reference: 'ORD-1001',
        metadata: $metadata,
    );
}

test('payload defaults to forward type and guesses city codes', function () {
    $payload = (new BostaPayloadFactory)->create(bostaUnitDraft(['cod' => 100]));

    expect($payload['type'])->toBe(DeliveryType::SEND)
        ->and($payload['cod'])->toBe(100)
        ->and($payload['businessReference'])->toBe('ORD-1001')
        ->and($payload['dropOffAddress']['city'])->toBe('EG-02')
        ->and($payload['receiver']['phone'])->toBe('01000000000');
});

test('payload supports return crp and exchange types via metadata', function () {
    $factory = new BostaPayloadFactory;

    expect($factory->create(bostaUnitDraft(['type' => 25]))['type'])->toBe(DeliveryType::CRP)
        ->and($factory->create(bostaUnitDraft(['type' => 30]))['type'])->toBe(DeliveryType::EXCHANGE);
});
