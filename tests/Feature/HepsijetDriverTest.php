<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Drivers\Hepsijet\HepsijetDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function hepsijetConnection(string $id = 'conn_hepsijet_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'hepsijet',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'username' => 'hj-user',
            'password' => 'hj-pass',
            'company_name' => 'Test Co',
            'abbreviation_code' => 'HJ',
            'company_address_id' => 'ADDR-1',
            'current_xdock_code' => 'XDOCK-1',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function hepsijetDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', state: 'Pendik', countryCode: 'TR', phone: '05551111111'),
        destination: new Address(name: 'Ali Yılmaz', line1: 'Atatürk Mah. No:5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Ayakkabı')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 150], $metadata),
    );
}

function hepsijetFake(array $stubs): Factory
{
    $http = new Factory;
    $http->fake(array_merge([
        '*auth/getToken' => Factory::response(['status' => 'OK', 'data' => ['token' => 'hj-token']], 200),
    ], $stubs));

    return $http;
}

test('hepsijet creates a shipment via senddeliveryorderenhanced', function () {
    $http = hepsijetFake([
        '*sendDeliveryOrderEnhanced' => Factory::response(['status' => 'OK', 'data' => ['customerDeliveryNo' => 'HJORD42']], 200),
    ]);

    $driver = HepsijetDriver::fromConnection($http, hepsijetConnection());
    $result = $driver->createShipment(hepsijetDraft(), hepsijetConnection());

    expect($result->id)->toBe('HJORD42')
        ->and($result->trackingNumber)->toBe('HJORD42')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('hepsijet')
        ->and($result->raw)->not->toHaveKey('password')
        ->and($result->raw)->not->toHaveKey('token');

    $posts = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'sendDeliveryOrderEnhanced')));
    expect($posts)->toHaveCount(1);

    $body = $posts[0][0]->body();
    expect($body)->toContain('HJORD42')
        ->and($body)->toContain('RETAIL')
        ->and($body)->toContain('150');

    $tokenHeader = implode(',', $posts[0][0]->header('X-Auth-Token') ?? []);
    expect($tokenHeader)->toBe('hj-token');
});

test('hepsijet return uses returned delivery type', function () {
    $http = hepsijetFake([
        '*sendDeliveryOrderEnhanced' => Factory::response(['status' => 'OK', 'data' => ['customerDeliveryNo' => 'HJRTN1']], 200),
    ]);

    $driver = HepsijetDriver::fromConnection($http, hepsijetConnection());
    $result = $driver->createShipment(hepsijetDraft(['shipment_kind' => 'return', 'reason' => 'Defective']), hepsijetConnection());

    expect($result->status)->toBe(ShipmentStatus::Returned);

    $posts = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'sendDeliveryOrderEnhanced')));
    expect($posts[0][0]->body())->toContain('RETURNED');
});

test('hepsijet create surfaces vendor status errors', function () {
    $http = hepsijetFake([
        '*sendDeliveryOrderEnhanced' => Factory::response(['status' => 'ERROR', 'message' => 'Invalid company'], 200),
    ]);

    expect(fn () => HepsijetDriver::fromConnection($http, hepsijetConnection())->createShipment(hepsijetDraft(), hepsijetConnection()))
        ->toThrow(ShippingException::class, 'Invalid company');
});

test('hepsijet requires abbreviation code for delivery numbers', function () {
    $http = hepsijetFake([
        '*sendDeliveryOrderEnhanced' => Factory::response(['status' => 'OK', 'data' => []], 200),
    ]);

    $connection = hepsijetConnection('conn_hj_2', ['abbreviation_code' => '']);

    expect(fn () => HepsijetDriver::fromConnection($http, $connection)->createShipment(hepsijetDraft(), $connection))
        ->toThrow(ShippingException::class, 'abbreviation_code');
});

test('hepsijet requires receiver phone', function () {
    $http = hepsijetFake([]);
    $driver = HepsijetDriver::fromConnection($http, hepsijetConnection());
    $draft = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'No Phone', line1: 'Atatürk Mah 5', city: 'Ankara', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, hepsijetConnection()))
        ->toThrow(ShippingException::class, 'phone');
});

test('hepsijet tracks with normalized detail events', function () {
    $http = hepsijetFake([
        '*integration/track' => Factory::response(['status' => 'OK', 'data' => [[
            'barcode' => 'HJORD42',
            'integrationStatus' => 'OUT_FOR_DELIVERY',
            'details' => [
                ['integrationStatus' => 'OUT_FOR_DELIVERY', 'transactionDate' => '2026-01-02T10:00:00', 'transactionLocation' => 'Ankara'],
                ['integrationStatus' => 'ACCEPTED', 'transactionDate' => '2026-01-01T10:00:00', 'transactionLocation' => 'İstanbul'],
            ],
        ]]], 200),
    ]);

    $tracked = HepsijetDriver::fromConnection($http, hepsijetConnection())->track('HJORD42', hepsijetConnection());

    expect($tracked->trackingNumber)->toBe('HJORD42')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->events[0]->location)->toBe('Ankara');
});

test('hepsijet track maps empty deliveries to not found', function () {
    $http = hepsijetFake([
        '*integration/track' => Factory::response(['status' => 'OK', 'data' => []], 200),
    ]);

    expect(fn () => HepsijetDriver::fromConnection($http, hepsijetConnection())->track('HJ-MISSING', hepsijetConnection()))
        ->toThrow(ShippingException::class, 'HJ-MISSING');
});

test('hepsijet label returns pdf contents', function () {
    $pdf = base64_encode('%PDF-1.4 hepsijet');

    $http = hepsijetFake([
        '*barcodes-label*' => Factory::response(['status' => 'OK', 'data' => ['label' => $pdf]], 200),
    ]);

    $label = HepsijetDriver::fromConnection($http, hepsijetConnection())->label('HJORD42', hepsijetConnection());

    expect($label->shipmentId)->toBe('HJORD42')
        ->and($label->contents)->toBe($pdf)
        ->and($label->base64Encoded)->toBeTrue();
});

test('hepsijet label throws on empty content', function () {
    $http = hepsijetFake([
        '*barcodes-label*' => Factory::response(['status' => 'OK', 'data' => []], 200),
    ]);

    expect(fn () => HepsijetDriver::fromConnection($http, hepsijetConnection())->label('HJORD42', hepsijetConnection()))
        ->toThrow(ShippingException::class, 'empty content');
});

test('hepsijet memoizes the auth token per client', function () {
    $http = hepsijetFake([
        '*sendDeliveryOrderEnhanced' => Factory::response(['status' => 'OK', 'data' => ['customerDeliveryNo' => 'HJ1']], 200),
        '*integration/track' => Factory::response(['status' => 'OK', 'data' => [[
            'barcode' => 'HJ1', 'integrationStatus' => 'DELIVERED', 'details' => [],
        ]]], 200),
    ]);

    $driver = HepsijetDriver::fromConnection($http, hepsijetConnection());
    $driver->createShipment(hepsijetDraft(), hepsijetConnection());
    $driver->track('HJ1', hepsijetConnection());

    $tokens = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'getToken')));
    expect($tokens)->toHaveCount(1);
});

test('hepsijet declares honest capabilities without cancellation', function () {
    $driver = HepsijetDriver::fromConnection(new Factory, hepsijetConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->cancel)->toBeFalse()
        ->and($caps->rates)->toBeFalse()
        ->and($driver)->not->toBeInstanceOf(SupportsCancellation::class);
});
