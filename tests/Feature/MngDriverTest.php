<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Drivers\Mng\MngDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function mngConnection(string $id = 'conn_mng_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'mng',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'client_id' => 'ibm-client',
            'client_secret' => 'ibm-secret',
            'customer_number' => '123456',
            'password' => 'panel-pass',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function mngDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR', phone: '05551111111'),
        destination: new Address(name: 'Ali Yılmaz', line1: 'Atatürk Mah. No:5', city: 'Adana', state: 'Seyhan', countryCode: 'TR', phone: '05551234567', email: 'ali@example.com'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Ayakkabı')],
        reference: 'ORD-42',
        metadata: array_merge(['city_code' => 1, 'district_code' => 100, 'cod' => 150], $metadata),
    );
}

function mngFake(array $stubs): Factory
{
    $http = new Factory;
    $http->fake(array_merge([
        '*mngapi/api/token' => Factory::response(['jwt' => 'test-jwt'], 200),
    ], $stubs));

    return $http;
}

test('mng creates a shipment via createorder plus createbarcode', function () {
    $http = mngFake([
        '*createOrder' => Factory::response(['shipmentId' => '9876543210', 'orderInvoiceId' => 'ORD-42'], 200),
        '*createbarcode' => Factory::response(['shipmentId' => '9876543210', 'barcodes' => [['barcode' => 'MNGBAR123', 'value' => 'ZPL-BYTES']]], 200),
    ]);

    $driver = MngDriver::fromConnection($http, mngConnection());
    $result = $driver->createShipment(mngDraft(), mngConnection());

    expect($result->id)->toBe('9876543210')
        ->and($result->trackingNumber)->toBe('MNGBAR123')
        ->and($result->status)->toBe(ShipmentStatus::Labeled)
        ->and($result->carrier)->toBe('mng')
        ->and($result->raw['reference_id'])->toBe('ORD-42')
        ->and($result->raw['zpl'])->toBe('ZPL-BYTES')
        ->and($result->raw)->not->toHaveKey('client_secret')
        ->and($result->raw)->not->toHaveKey('password')
        ->and($result->raw)->not->toHaveKey('jwt');

    $bodies = array_map(fn ($r) => $r[0]->body(), $http->recorded()->all());
    $joined = implode("\n", $bodies);

    expect($joined)->toContain('"referenceId":"ORD-42"')
        ->and($joined)->toContain('"isCOD":1')
        ->and($joined)->toContain('"codAmount":150')
        ->and($joined)->toContain('"cityCode":1');

    $barcodes = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'createbarcode')));
    expect($barcodes)->toHaveCount(1);

    $tokenCalls = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), '/token')));
    expect($tokenCalls)->toHaveCount(1);
});

test('mng create skips barcode when disabled', function () {
    $http = mngFake([
        '*createOrder' => Factory::response(['shipmentId' => '9876543211'], 200),
    ]);

    $driver = MngDriver::fromConnection($http, mngConnection());
    $result = $driver->createShipment(mngDraft(['create_barcode' => false]), mngConnection());

    expect($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->trackingNumber)->toBe('9876543211');

    $barcodes = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'createbarcode')));
    expect($barcodes)->toHaveCount(0);
});

test('mng return travels via createreturnorder', function () {
    $http = mngFake([
        '*createReturnOrder' => Factory::response(['returnOrderId' => 'RTN-1', 'returnOrderLabelURL' => 'https://x/ret.pdf'], 200),
    ]);

    $driver = MngDriver::fromConnection($http, mngConnection());
    $result = $driver->createShipment(mngDraft(['shipment_kind' => 'return', 'reason' => 'Defective']), mngConnection());

    expect($result->trackingNumber)->toBe('RTN-1')
        ->and($result->labelUrl)->toBe('https://x/ret.pdf');

    $bodies = implode("\n", array_map(fn ($r) => $r[0]->body(), $http->recorded()->all()));
    expect($bodies)->toContain('Defective');
});

test('mng create maps api error envelope to carrier exception', function () {
    $http = mngFake([
        '*createOrder' => Factory::response(['error' => ['Description' => 'VARIŞ ŞUBESİ not ready']], 400),
    ]);

    $driver = MngDriver::fromConnection($http, mngConnection());

    expect(fn () => $driver->createShipment(mngDraft(), mngConnection()))
        ->toThrow(ShippingException::class, 'VARIŞ ŞUBESİ not ready');
});

test('mng requires ibm credentials before any call', function () {
    $http = mngFake([]);
    $connection = mngConnection('conn_mng_2', ['client_id' => '', 'client_secret' => '']);
    $driver = MngDriver::fromConnection($http, $connection);

    expect(fn () => $driver->createShipment(mngDraft(), $connection))
        ->toThrow(ShippingException::class, 'client_id');
});

test('mng requires city code or city name', function () {
    $http = mngFake([
        '*createOrder' => Factory::response(['shipmentId' => '1'], 200),
    ]);

    $driver = MngDriver::fromConnection($http, mngConnection());
    $draft = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: '', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Atatürk Mah 5', city: '', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, mngConnection()))
        ->toThrow(ShippingException::class, 'city_code');
});

test('mng tracks with normalized status codes and events', function () {
    $http = mngFake([
        '*getshipmentstatus*' => Factory::response(['shipmentId' => '9876543210', 'shipmentStatusCode' => '4'], 200),
        '*trackshipment*' => Factory::response(['shipmentTrackingList' => [
            ['shipmentStatusCode' => '4', 'description' => 'Alıcı Adresine Yönlendirildi', 'eventDate' => '2026-01-02', 'branchName' => 'Adana'],
            ['shipmentStatusCode' => '2', 'description' => 'Transfer Aşamasında', 'eventDate' => '2026-01-01', 'branchName' => 'İstanbul'],
        ]], 200),
    ]);

    $tracked = MngDriver::fromConnection($http, mngConnection())->track('9876543210', mngConnection());

    expect($tracked->trackingNumber)->toBe('9876543210')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->location)->toBe('Adana')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::InTransit)
        ->and($tracked->raw)->not->toHaveKey('client_secret');
});

test('mng track tolerates a failing history call', function () {
    $http = mngFake([
        '*getshipmentstatus*' => Factory::response(['shipmentId' => '9876543210', 'shipmentStatusCode' => '5'], 200),
        '*trackshipment*' => Factory::response(['message' => 'boom'], 500),
    ]);

    $tracked = MngDriver::fromConnection($http, mngConnection())->track('9876543210', mngConnection());

    expect($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(0);
});

test('mng cancels via cancelorder', function () {
    $http = mngFake([
        '*cancelorder*' => Factory::response(['result' => 'ok'], 200),
    ]);

    expect(MngDriver::fromConnection($http, mngConnection())->cancel('ORD-42', mngConnection()))->toBeTrue();

    $found = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->url(), 'cancelorder/ORD-42')));
    expect($found)->toHaveCount(1);
});

test('mng cancel failure maps to carrier exception', function () {
    $http = mngFake([
        '*cancelorder*' => Factory::response(['error' => ['Description' => 'Already dispatched']], 400),
    ]);

    expect(fn () => MngDriver::fromConnection($http, mngConnection())->cancel('ORD-42', mngConnection()))
        ->toThrow(ShippingException::class, 'Already dispatched');
});

test('mng declares honest capabilities', function () {
    $caps = MngDriver::fromConnection(new Factory, mngConnection())->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->label)->toBeFalse()
        ->and($caps->rates)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse()
        ->and(MngDriver::fromConnection(new Factory, mngConnection()))->not->toBeInstanceOf(SupportsLabels::class);
});
