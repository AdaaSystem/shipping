<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Dhl\DhlDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function dhlConnection(string $id = 'conn_dhl_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'dhl',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: array_merge([
            'username' => 'test-user',
            'password' => 'test-pass',
            'account_number' => '123456789',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function dhlDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Acme Lab', line1: 'Karl-Liebknecht-Str. 13', city: 'Berlin', countryCode: 'DE', postalCode: '10178', phone: '+49301234567'),
        destination: new Address(name: 'Jane Receiver', line1: 'Hamburger Str. 1', city: 'Hamburg', countryCode: 'DE', postalCode: '20099', phone: '+49401234567'),
        parcels: [new Parcel(weightKg: 5.0, description: 'Glass jars', lengthCm: 10, widthCm: 30, heightCm: 20)],
        reference: 'ORD-42',
        metadata: array_merge(['product_code' => 'N', 'incoterm' => 'DAP'], $metadata),
    );
}

function dhlDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, DhlDriver::fromConnection($http, dhlConnection())];
}

function dhlRatesResponse(): array
{
    return [
        'products' => [
            [
                'productCode' => 'P',
                'productName' => 'EXPRESS WORLDWIDE',
                'totalPrice' => [['currencyType' => 'BILLC', 'price' => 25.5, 'priceCurrency' => 'EUR']],
                'deliveryCapabilities' => ['estimatedDeliveryDateAndTime' => '2026-10-15T12:00:00'],
            ],
            [
                'productCode' => 'N',
                'productName' => 'EXPRESS DOMESTIC',
                'totalPrice' => [['currencyType' => 'BILLC', 'price' => 12.0, 'priceCurrency' => 'EUR']],
                'deliveryCapabilities' => ['estimatedDeliveryDateAndTime' => '2026-10-14T12:00:00'],
            ],
        ],
    ];
}

function dhlCreateResponse(): array
{
    return [
        'shipmentTrackingNumber' => '1234567890',
        'dispatchConfirmationNumber' => 'PRG999126012345',
        'trackingUrl' => 'https://www.dhl.com/en/express/tracking.html?AWB=1234567890',
        'documents' => [
            ['typeCode' => 'label', 'imageFormat' => 'PDF', 'content' => base64_encode('%PDF-1.4 label')],
        ],
    ];
}

function dhlTrackResponse(): array
{
    return [
        'shipments' => [[
            'shipmentTrackingNumber' => '1234567890',
            'status' => 'Delivered',
            'events' => [
                [
                    'date' => '2026-01-02',
                    'time' => '10:00:00',
                    'GMTOffset' => '+02:00',
                    'typeCode' => 'DL',
                    'description' => 'Shipment delivered',
                    'serviceArea' => [['code' => 'HAM', 'description' => 'Hamburg']],
                ],
                [
                    'date' => '2026-01-01',
                    'time' => '08:00:00',
                    'GMTOffset' => '+01:00',
                    'typeCode' => 'PU',
                    'description' => 'Shipment picked up',
                    'serviceArea' => [['code' => 'BER', 'description' => 'Berlin']],
                ],
            ],
            'pieces' => [],
        ]],
    ];
}

test('dhl rates a one-piece shipment via GET with basic auth', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(dhlRatesResponse(), 200),
    ]);

    $quotes = $driver->rates(dhlDraft(), dhlConnection());

    expect($quotes)->toHaveCount(2)
        ->and($quotes[0]->serviceCode)->toBe('P')
        ->and($quotes[0]->serviceName)->toBe('EXPRESS WORLDWIDE')
        ->and($quotes[0]->amount)->toBe(25.5)
        ->and($quotes[0]->currency)->toBe('EUR')
        ->and($quotes[0]->estimatedDelivery)->toBe('2026-10-15T12:00:00')
        ->and($quotes[0]->carrierCode)->toBe('dhl');

    [$request] = $http->recorded()[0];

    expect($request->method())->toBe('GET')
        ->and($request->header('Authorization'))->toBe(['Basic '.base64_encode('test-user:test-pass')])
        ->and($request->header('x-version'))->toBe(['3.3.1'])
        ->and($request->url())->toContain('rates')
        ->and($request['accountNumber'] ?? null)->toBe('123456789');
});

test('dhl rates multi-piece shipments via POST', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(dhlRatesResponse(), 200),
    ]);

    $draft = dhlDraft();
    $draft = new ShipmentDraft(
        origin: $draft->origin,
        destination: $draft->destination,
        parcels: [
            new Parcel(weightKg: 5.0, lengthCm: 10, widthCm: 30, heightCm: 20),
            new Parcel(weightKg: 2.0, lengthCm: 10, widthCm: 20, heightCm: 10),
        ],
        reference: 'ORD-42',
        metadata: $draft->metadata,
    );

    $quotes = $driver->rates($draft, dhlConnection());

    expect($quotes)->toHaveCount(2);

    [$request] = $http->recorded()[0];

    expect($request->method())->toBe('POST')
        ->and($request->url())->toContain('rates')
        ->and($request->data()['packages'] ?? null)->toHaveCount(2);
});

test('dhl rates throw when no priced products', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(['products' => []], 200),
    ]);

    expect(fn () => $driver->rates(dhlDraft(), dhlConnection()))
        ->toThrow(ShippingException::class, 'no priced products');
});

test('dhl creates a shipment with basic auth and inline label', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(dhlCreateResponse(), 200),
    ]);

    $result = $driver->createShipment(dhlDraft(), dhlConnection());

    expect($result->id)->toBe('1234567890')
        ->and($result->trackingNumber)->toBe('1234567890')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('dhl')
        ->and($result->labelUrl)->toContain('1234567890')
        ->and($result->raw['has_label_contents'])->toBeTrue()
        ->and($result->raw['label_contents'])->toBe(base64_encode('%PDF-1.4 label'))
        ->and($result->raw)->not->toHaveKey('password')
        ->and($result->raw)->not->toHaveKey('username');

    [$request] = $http->recorded()[0];

    expect($request->header('Authorization'))->toBe(['Basic '.base64_encode('test-user:test-pass')])
        ->and($request->data()['productCode'] ?? null)->toBe('N')
        ->and($request->data()['customerDetails']['shipperDetails']['postalAddress']['countryCode'] ?? null)->toBe('DE')
        ->and($request->data()['customerDetails']['receiverDetails']['contactInformation']['phone'] ?? null)->toBe('+49401234567')
        ->and($request->data()['content']['packages'][0]['weight'] ?? null)->toBe(5.0)
        ->and($request->data()['content']['description'] ?? null)->toBe('Glass jars')
        ->and($request->data()['customerReferences'][0]['value'] ?? null)->toBe('ORD-42');
});

test('dhl create throws without tracking number', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(['dispatchConfirmationNumber' => 'PRG1'], 200),
    ]);

    expect(fn () => $driver->createShipment(dhlDraft(), dhlConnection()))
        ->toThrow(ShippingException::class, 'no shipmentTrackingNumber');
});

test('dhl create requires account number and phones', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(dhlCreateResponse(), 200),
    ]);

    $noAccount = dhlDraft();
    $connection = new ShippingConnectionContext(
        connectionId: 'conn_dhl_no_account',
        carrierCode: 'dhl',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: ['username' => 'u', 'password' => 'p'],
        merchantId: 'merchant_7',
    );
    $driverNoAccount = DhlDriver::fromConnection($http, $connection);

    expect(fn () => $driverNoAccount->createShipment($noAccount, $connection))
        ->toThrow(ShippingException::class, 'account number');

    $noPhone = new ShipmentDraft(
        origin: new Address(name: 'A', line1: 'x', city: 'Berlin', countryCode: 'DE'),
        destination: new Address(name: 'B', line1: 'y', city: 'Hamburg', countryCode: 'DE'),
        parcels: [new Parcel(weightKg: 1.0)],
    );

    expect(fn () => $driver->createShipment($noPhone, dhlConnection()))
        ->toThrow(ShippingException::class, 'phone');
});

test('dhl tracks with normalized events', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(dhlTrackResponse(), 200),
    ]);

    $tracked = $driver->track('1234567890', dhlConnection());

    expect($tracked->trackingNumber)->toBe('1234567890')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events[0]->description)->toBe('Shipment delivered')
        ->and($tracked->events[0]->location)->toBe('Hamburg')
        ->and($tracked->events[0]->occurredAt)->toContain('2026-01-02')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->raw)->not->toHaveKey('password');
});

test('dhl throws for unknown tracking numbers', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(['shipments' => []], 200),
    ]);

    expect(fn () => $driver->track('UNKNOWN', dhlConnection()))
        ->toThrow(ShippingException::class);
});

test('dhl maps auth failures with status code', function () {
    [$http, $driver] = dhlDriverWithFake([
        '*' => Factory::response(['title' => 'Unauthorized', 'detail' => 'Bad credentials'], 401),
    ]);

    try {
        $driver->track('1234567890', dhlConnection());
        expect(false)->toBeTrue('expected ShippingException');
    } catch (ShippingException $e) {
        expect($e->getCode())->toBe(401)
            ->and($e->getMessage())->toContain('authentication failed');
    }
});

test('dhl throws when credentials are missing', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response([], 200)]);
    $connection = new ShippingConnectionContext(
        connectionId: 'conn_dhl_empty',
        carrierCode: 'dhl',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: [],
        merchantId: 'merchant_7',
    );
    $driver = DhlDriver::fromConnection($http, $connection);

    expect(fn () => $driver->track('1234567890', $connection))
        ->toThrow(ShippingException::class, 'Missing DHL credentials');
});

test('dhl uses the test host in sandbox and production host otherwise', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(dhlTrackResponse(), 200)]);

    $sandbox = DhlDriver::fromConnection($http, dhlConnection());
    $sandbox->track('1234567890', dhlConnection());

    $productionConnection = new ShippingConnectionContext(
        connectionId: 'conn_dhl_prod',
        carrierCode: 'dhl',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: ['username' => 'u', 'password' => 'p', 'account_number' => '1'],
        merchantId: 'merchant_7',
    );
    $production = DhlDriver::fromConnection($http, $productionConnection);
    $production->track('1234567890', $productionConnection);

    $urls = $http->recorded()->map(static fn ($pair): string => $pair[0]->url())->all();

    expect($urls[0])->toContain('mydhlapi/test')
        ->and($urls[1])->toContain('express.api.dhl.com/mydhlapi')
        ->and($urls[1])->not->toContain('mydhlapi/test');
});

test('dhl declares honest capabilities and exposes no label or cancel', function () {
    $driver = DhlDriver::fromConnection(new Factory, dhlConnection());
    $caps = $driver->capabilities();

    expect($caps->rates)->toBeTrue()
        ->and($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeFalse()
        ->and($caps->cancel)->toBeFalse()
        ->and($caps->return)->toBeFalse()
        ->and($caps->exchange)->toBeFalse()
        ->and($caps->cod)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse()
        ->and(method_exists($driver, 'label'))->toBeFalse()
        ->and(method_exists($driver, 'cancel'))->toBeFalse();
});
