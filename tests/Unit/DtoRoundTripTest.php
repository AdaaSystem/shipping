<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;

function sampleConnectionArray(): array
{
    return (new ShippingConnectionContext(
        connectionId: 'conn_1',
        carrierCode: 'fake',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: ['api_key' => 'x'],
        merchantId: 'm1',
    ))->toArray();
}

function sampleDraftArray(): array
{
    return (new ShipmentDraft(
        origin: new Address(name: 'W', line1: 'l1', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'C', line1: 'l2', city: 'Giza', countryCode: 'EG', phone: '010'),
        parcels: [new Parcel(weightKg: 1.5, description: 'T', quantity: 2)],
        reference: 'R-1',
        metadata: ['cod' => 5],
        idempotencyKey: 'k-1',
    ))->toArray();
}

test('connection context round-trips through arrays', function () {
    $context = ShippingConnectionContext::fromArray(sampleConnectionArray());

    expect($context->connectionId)->toBe('conn_1')
        ->and($context->mode)->toBe(ConnectionMode::Merchant)
        ->and($context->environment)->toBe(Environment::Sandbox)
        ->and($context->credentials)->toBe(['api_key' => 'x'])
        ->and($context->cacheKey('t'))->toBe('shipping:token:conn_1:t');
});

test('shipment draft round-trips through arrays', function () {
    $draft = ShipmentDraft::fromArray(sampleDraftArray());

    expect($draft->reference)->toBe('R-1')
        ->and($draft->idempotencyKey)->toBe('k-1')
        ->and($draft->parcels)->toHaveCount(1)
        ->and($draft->parcels[0]->weightKg)->toBe(1.5)
        ->and($draft->parcels[0]->quantity)->toBe(2)
        ->and($draft->destination->phone)->toBe('010')
        ->and($draft->toArray()['metadata'])->toBe(['cod' => 5]);
});
