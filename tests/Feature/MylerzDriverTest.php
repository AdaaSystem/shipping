<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Mylerz\MylerzDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function mylerzConnection(string $id = 'conn_mylerz_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'mylerz',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'username' => 'test-user',
            'password' => 'test-pass',
            'access_token' => 'test-token',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function mylerzDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.2, description: 'T-shirt')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 250, 'neighborhood' => 'Dokki', 'warehouse' => 'Main'], $metadata),
    );
}

function mylerzDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, MylerzDriver::fromConnection($http, mylerzConnection())];
}

test('mylerz creates a shipment with bearer auth', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response([
            'IsErrorState' => false,
            'Value' => [
                'Packages' => [[
                    'BarCode' => 'MZ-1001',
                    'Reference' => '#ORD-42',
                    'Status' => 'CREATED',
                ]],
            ],
        ], 200),
    ]);

    $result = $driver->createShipment(mylerzDraft(), mylerzConnection());

    expect($result->id)->toBe('MZ-1001')
        ->and($result->trackingNumber)->toBe('MZ-1001')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('mylerz')
        ->and($result->raw)->not->toHaveKey('password')
        ->and($result->raw)->not->toHaveKey('access_token')
        ->and($result->raw)->not->toHaveKey('username');

    [$request] = $http->recorded()[0];

    expect($request->header('Authorization'))->toBe(['Bearer test-token'])
        ->and($request->data()[0]['Payment_Type'] ?? null)->toBe('COD')
        ->and($request->data()[0]['COD_Value'] ?? null)->toBe(250.0)
        ->and($request->data()[0]['Neighborhood'] ?? null)->toBe('Dokki')
        ->and($request->data()[0]['Mobile_No'] ?? null)->toBe('01000000000');
});

test('mylerz create surfaces carrier error envelopes', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response(['IsErrorState' => true, 'ErrorDescription' => 'Invalid warehouse'], 200),
    ]);

    expect(fn () => $driver->createShipment(mylerzDraft(), mylerzConnection()))
        ->toThrow(ShippingException::class, 'Invalid warehouse');
});

test('mylerz requires phone and neighborhood', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response(['IsErrorState' => false, 'Value' => []], 200),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No Phone', line1: '12 Nile St', city: '', countryCode: 'EG'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, mylerzConnection()))
        ->toThrow(ShippingException::class);
});

test('mylerz tracks with normalized status', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response([
            'IsErrorState' => false,
            'Value' => [[
                'Barcode' => 'MZ-1001',
                'Status' => 'OUT FOR DELIVERY',
                'StatusDate' => '2026-07-16T12:00:00',
                'Hub' => 'Giza Hub',
            ]],
        ], 200),
    ]);

    $tracked = $driver->track('MZ-1001', mylerzConnection());

    expect($tracked->trackingNumber)->toBe('MZ-1001')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(1)
        ->and($tracked->events[0]->description)->toBe('OUT FOR DELIVERY')
        ->and($tracked->events[0]->location)->toBe('Giza Hub')
        ->and($tracked->raw)->not->toHaveKey('password');
});

test('mylerz throws for unknown tracking numbers', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response(['IsErrorState' => false, 'Value' => []], 200),
    ]);

    expect(fn () => $driver->track('UNKNOWN', mylerzConnection()))
        ->toThrow(ShippingException::class);
});

test('mylerz label fetches awb', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response([
            'IsErrorState' => false,
            'Value' => ['AWB' => base64_encode('%PDF-1.4 label')],
        ], 200),
    ]);

    $label = $driver->label('MZ-1001', mylerzConnection());

    expect($label->shipmentId)->toBe('MZ-1001')
        ->and($label->contents)->toBe(base64_encode('%PDF-1.4 label'))
        ->and($label->base64Encoded)->toBeTrue();
});

test('mylerz label throws when empty', function () {
    [$http, $driver] = mylerzDriverWithFake([
        '*' => Factory::response(['IsErrorState' => false, 'Value' => []], 200),
    ]);

    expect(fn () => $driver->label('MZ-1001', mylerzConnection()))
        ->toThrow(ShippingException::class);
});

test('mylerz supports return and exchange categories via metadata', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response([
            'IsErrorState' => false,
            'Value' => ['Packages' => [['BarCode' => 'MZ-RET-1', 'Status' => 'CREATED']]],
        ], 200),
    ]);

    $driver = MylerzDriver::fromConnection($http, mylerzConnection());

    $driver->createShipment(mylerzDraft(['service_category' => 'RETURN']), mylerzConnection());
    [$request] = $http->recorded()[0];

    expect($request->data()[0]['Service_Category'] ?? null)->toBe('RETURN');
});

test('mylerz declares honest capabilities', function () {
    $driver = MylerzDriver::fromConnection(new Factory, mylerzConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->rates)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse();
});
