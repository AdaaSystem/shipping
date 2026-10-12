<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Bosta\BostaDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function bostaConnection(string $id = 'conn_bosta_1', array $credentials = ['api_key' => 'test-key']): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'bosta',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: $credentials,
        merchantId: 'merchant_7',
    );
}

function bostaDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt')],
        reference: 'ORD-1001',
        metadata: array_merge(['cod' => 450, 'city_code' => 'EG-02', 'zone' => 'Dokki', 'building_number' => 12, 'notes' => 'Call first'], $metadata),
    );
}

function bostaDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, BostaDriver::fromConnection($http, bostaConnection())];
}

test('bosta creates a shipment with raw api key auth', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response([
            'success' => true,
            'message' => 'Created',
            'data' => [
                '_id' => 'del_123',
                'trackingNumber' => 'BST123456',
                'state' => ['value' => 'Pickup requested'],
            ],
        ], 200),
    ]);

    $result = $driver->createShipment(bostaDraft(), bostaConnection());

    expect($result->id)->toBe('del_123')
        ->and($result->trackingNumber)->toBe('BST123456')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('bosta')
        ->and($result->raw)->not->toHaveKey('api_key');

    [$request] = $http->recorded()[0];

    expect($request->header('Authorization'))->toBe(['test-key'])
        ->and($request->data()['businessReference'] ?? null)->toBe('ORD-1001')
        ->and($request->data()['cod'] ?? null)->toBe(450)
        ->and($request->data()['dropOffAddress']['city'] ?? null)->toBe('EG-02');
});

test('bosta create surfaces carrier error envelopes', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response(['success' => false, 'message' => 'Invalid city'], 200),
    ]);

    expect(fn () => $driver->createShipment(bostaDraft(), bostaConnection()))
        ->toThrow(ShippingException::class, 'Invalid city');
});

test('bosta requires receiver phone', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response(['success' => true, 'data' => []], 200),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No Phone', line1: '12 Nile St', city: 'Giza', countryCode: 'EG'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, bostaConnection()))
        ->toThrow(ShippingException::class, 'receiver phone');
});

test('bosta tracks with normalized events', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response([
            'success' => true,
            'data' => [
                'deliveries' => [
                    [
                        'trackingNumber' => 'BST123456',
                        'state' => ['value' => 'Delivered'],
                        'tracking' => [
                            ['state' => ['value' => 'Picked up'], 'description' => 'Picked up', 'date' => '2026-01-01T10:00:00Z'],
                            ['state' => ['value' => 'Delivered'], 'description' => 'Delivered', 'date' => '2026-01-02T10:00:00Z'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $tracked = $driver->track('BST123456', bostaConnection());

    expect($tracked->trackingNumber)->toBe('BST123456')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::Delivered);
});

test('bosta label falls back from mass-awb to single awb', function () {
    $http = new Factory;
    $http->fake(function ($request) {
        if (str_contains($request->url(), 'mass-awb')) {
            return Factory::response(['success' => false, 'message' => 'Not found'], 404);
        }

        return Factory::response(['success' => true, 'data' => ['awb' => base64_encode('%PDF-1.4 label')]], 200);
    });

    $driver = BostaDriver::fromConnection($http, bostaConnection());
    $label = $driver->label('del_123', bostaConnection());

    expect($label->shipmentId)->toBe('del_123')
        ->and($label->contents)->toBe(base64_encode('%PDF-1.4 label'))
        ->and($label->base64Encoded)->toBeTrue();
});

test('bosta cancels via terminate', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response(['success' => true, 'message' => 'Terminated', 'data' => []], 200),
    ]);

    expect($driver->cancel('del_123', bostaConnection()))->toBeTrue();
});

test('bosta throws for unknown tracking numbers', function () {
    [$http, $driver] = bostaDriverWithFake([
        '*' => Factory::response(['success' => true, 'data' => ['deliveries' => []]], 200),
    ]);

    expect(fn () => $driver->track('UNKNOWN', bostaConnection()))
        ->toThrow(ShippingException::class);
});

test('bosta declares honest capabilities', function () {
    $driver = BostaDriver::fromConnection(new Factory, bostaConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->webhooks)->toBeTrue()
        ->and($caps->rates)->toBeFalse();
});
