<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Manager\ShippingManager;

function merchantConnection(string $id, string $merchant, string $carrier = 'fake', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: $carrier,
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: $credentials,
        merchantId: $merchant,
    );
}

function sampleDraft(): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'Ahmed', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.2)],
        reference: 'ORD-42',
    );
}

test('each connection resolves a driver with its own credentials', function () {
    $seen = [];

    $manager = new ShippingManager;
    $manager->extend('fake', function (ShippingConnectionContext $connection) use (&$seen) {
        $seen[$connection->connectionId] = $connection->credentials['api_key'] ?? null;

        return new FakeDriver;
    });

    $connA = merchantConnection('conn_1', 'merchant_A', 'fake', ['api_key' => 'KEY_A']);
    $connB = merchantConnection('conn_2', 'merchant_B', 'fake', ['api_key' => 'KEY_B']);

    $manager->driverFor($connA);
    $manager->driverFor($connB);

    // No shared global config: every resolution carries its own connection.
    expect($seen)->toBe(['conn_1' => 'KEY_A', 'conn_2' => 'KEY_B']);
});

test('shipments created through one shared driver keep their connection tag', function () {
    $manager = new ShippingManager;
    $manager->share('fake', new FakeDriver);

    $driver = $manager->shared('fake');

    $first = $driver->createShipment(sampleDraft(), merchantConnection('conn_1', 'merchant_A'));
    $second = $driver->createShipment(sampleDraft(), merchantConnection('conn_2', 'merchant_B'));

    expect($first->trackingNumber)->not->toBe($second->trackingNumber)
        ->and($driver->track($first->trackingNumber, merchantConnection('conn_1', 'merchant_A'))->status)
        ->toBe(ShipmentStatus::Created);
});

test('fake driver tracks and advances shipments', function () {
    $driver = new FakeDriver;
    $connection = merchantConnection('conn_1', 'merchant_A');

    $shipment = $driver->createShipment(sampleDraft(), $connection);

    $driver->advance($shipment->trackingNumber, ShipmentStatus::Delivered, 'Delivered');

    expect($driver->track($shipment->trackingNumber, $connection)->status)->toBe(ShipmentStatus::Delivered);
});

test('unknown carrier throws a clear exception', function () {
    $manager = new ShippingManager;

    expect(fn () => $manager->driverFor(merchantConnection('conn_x', 'merchant_A', 'nope')))
        ->toThrow(ShippingException::class);
});
