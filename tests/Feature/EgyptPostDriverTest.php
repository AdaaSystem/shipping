<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\EgyptPost\EgyptPostDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function egyptPostConnection(string $id = 'conn_egyptpost_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'egyptpost',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: $credentials,
        merchantId: 'merchant_7',
    );
}

function egyptPostDraft(): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.2)],
        reference: 'ORD-42',
    );
}

function egyptPostDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, EgyptPostDriver::fromConnection($http, egyptPostConnection())];
}

test('egyptpost tracks via official tracktrace', function () {
    [$http, $driver] = egyptPostDriverWithFake([
        '*' => Factory::response([
            'Success' => true,
            'Data' => [
                'Barcode' => 'EP-12345',
                'Status' => 'OUT_FOR_DELIVERY',
                'History' => [
                    [
                        'Status' => 'REGISTERED',
                        'Description' => 'Shipment accepted',
                        'Date' => '2026-07-15 09:00:00',
                        'Location' => 'Cairo Main Office',
                    ],
                    [
                        'Status' => 'OUT_FOR_DELIVERY',
                        'Description' => 'With courier',
                        'Date' => '2026-07-16 10:00:00',
                        'Location' => 'Giza',
                    ],
                ],
            ],
        ], 200),
    ]);

    $tracked = $driver->track('EP-12345', egyptPostConnection());

    expect($tracked->trackingNumber)->toBe('EP-12345')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->raw)->not->toHaveKey('api_key')
        ->and($tracked->raw)->not->toHaveKey('password');
});

test('egyptpost parses embedded json from html responses', function () {
    [$http, $driver] = egyptPostDriverWithFake([
        '*' => Factory::response(
            '<html><body>{"barcode":"EP-99","status":"DELIVERED","events":[{"status":"DELIVERED","description":"Delivered to recipient","date":"2026-07-16"}]}</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $tracked = $driver->track('EP-99', egyptPostConnection());

    expect($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(1);
});

test('egyptpost label returns public track url with empty contents', function () {
    [$http, $driver] = egyptPostDriverWithFake(['*' => Factory::response([], 200)]);

    $label = $driver->label('EP-LABEL-1', egyptPostConnection());

    expect($label->shipmentId)->toBe('EP-LABEL-1')
        ->and($label->contents)->toBe('')
        ->and($label->base64Encoded)->toBeFalse()
        ->and($label->url)->toContain('EP-LABEL-1');
});

test('egyptpost creation throws unsupported operation', function () {
    [$http, $driver] = egyptPostDriverWithFake(['*' => Factory::response([], 200)]);

    expect(fn () => $driver->createShipment(egyptPostDraft(), egyptPostConnection()))
        ->toThrow(ShippingException::class, 'does not support');
});

test('egyptpost return and exchange throw unsupported operation', function () {
    [$http, $driver] = egyptPostDriverWithFake(['*' => Factory::response([], 200)]);

    expect(fn () => $driver->createReturn(egyptPostDraft(), egyptPostConnection()))
        ->toThrow(ShippingException::class, 'does not support');

    expect(fn () => $driver->createExchange(egyptPostDraft(), egyptPostConnection()))
        ->toThrow(ShippingException::class, 'does not support');
});

test('egyptpost track surfaces http failures', function () {
    [$http, $driver] = egyptPostDriverWithFake([
        '*' => Factory::response('Server error', 500),
    ]);

    expect(fn () => $driver->track('EP-1', egyptPostConnection()))
        ->toThrow(ShippingException::class);
});

test('egyptpost declares tracking-only capabilities', function () {
    $driver = EgyptPostDriver::fromConnection(new Factory, egyptPostConnection());
    $caps = $driver->capabilities();

    expect($caps->track)->toBeTrue()
        ->and($caps->create)->toBeFalse()
        ->and($caps->label)->toBeFalse()
        ->and($caps->rates)->toBeFalse()
        ->and($caps->cancel)->toBeFalse()
        ->and($caps->return)->toBeFalse()
        ->and($caps->exchange)->toBeFalse()
        ->and($caps->cod)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse();
});
