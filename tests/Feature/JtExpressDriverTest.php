<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\JtExpress\JtExpressDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

function jtexpressConnection(string $id = 'conn_jtexpress_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'jtexpress',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: array_merge([
            'api_account' => 'test-account',
            'private_key' => 'test-private-key',
            'customer_code' => 'J0099999999',
            'customer_pwd' => 'test-password',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function jtexpressDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG', phone: '01011111111'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.2, description: 'T-shirt')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 250, 'prov' => 'Giza', 'city' => 'Dokki'], $metadata),
    );
}

function jtexpressDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, JtExpressDriver::fromConnection($http, jtexpressConnection())];
}

/**
 * @return array<string, mixed>
 */
function jtexpressBizContent(Request $request): array
{
    $decoded = json_decode((string) ($request->data()['bizContent'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

test('jtexpress creates a shipment with signed headers and cod pay type', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response([
            'code' => '1',
            'msg' => 'success',
            'data' => [
                'billCode' => 'JT1000000001',
                'txlogisticId' => 'ORD-42',
                'sortingCode' => 'SC001',
            ],
        ], 200),
    ]);

    $result = $driver->createShipment(jtexpressDraft(), jtexpressConnection());

    expect($result->id)->toBe('ORD-42')
        ->and($result->trackingNumber)->toBe('JT1000000001')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('jtexpress')
        ->and($result->raw)->not->toHaveKey('private_key')
        ->and($result->raw)->not->toHaveKey('customer_pwd')
        ->and($result->raw)->not->toHaveKey('digest');

    [$request] = $http->recorded()[0];
    $biz = jtexpressBizContent($request);

    expect($request->header('apiAccount'))->toBe(['test-account'])
        ->and($request->header('digest')[0] ?? '')->not->toBe('')
        ->and($request->header('timestamp')[0] ?? '')->not->toBe('')
        ->and($biz['txlogisticId'] ?? null)->toBe('ORD-42')
        ->and($biz['customerCode'] ?? null)->toBe('J0099999999')
        ->and($biz['expressType'] ?? null)->toBe('EZ')
        ->and($biz['payType'] ?? null)->toBe('PP_CASH')
        ->and($biz['itemsValue'] ?? null)->toBe(250)
        ->and($biz['weight'] ?? null)->toBe(1.2)
        ->and($biz['receiver']['mobile'] ?? null)->toBe('01000000000')
        ->and($biz['receiver']['prov'] ?? null)->toBe('Giza')
        ->and($biz['receiver']['city'] ?? null)->toBe('Dokki');
});

test('jtexpress create uses prepaid pay type without cod', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => ['billCode' => 'JT1', 'txlogisticId' => 'ORD-1']], 200),
    ]);

    $driver = JtExpressDriver::fromConnection($http, jtexpressConnection());
    $driver->createShipment(jtexpressDraft(['cod' => 0]), jtexpressConnection());

    [$request] = $http->recorded()[0];
    $biz = jtexpressBizContent($request);

    expect($biz['payType'] ?? null)->toBe('PP_PM');
});

test('jtexpress create surfaces carrier error envelopes', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '0', 'msg' => 'Illegal parameters'], 200),
    ]);

    expect(fn () => $driver->createShipment(jtexpressDraft(), jtexpressConnection()))
        ->toThrow(ShippingException::class, 'Illegal parameters');
});

test('jtexpress create throws without bill code', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200),
    ]);

    expect(fn () => $driver->createShipment(jtexpressDraft(), jtexpressConnection()))
        ->toThrow(ShippingException::class, 'no billCode');
});

test('jtexpress requires receiver phone and prov/city', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200),
    ]);

    $noPhone = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No Phone', line1: '12 Nile St', city: 'Giza', countryCode: 'EG'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noPhone, jtexpressConnection()))
        ->toThrow(ShippingException::class, 'receiver phone');

    $noCity = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No City', line1: '12 Nile St', city: '', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noCity, jtexpressConnection()))
        ->toThrow(ShippingException::class, 'prov + city');
});

test('jtexpress requires auth credentials', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200)]);

    // Missing api_account/private_key/customer_code fails fast at driver construction.
    expect(fn () => JtExpressDriver::fromConnection($http, jtexpressConnection(credentials: ['api_account' => '', 'private_key' => '', 'customer_code' => '', 'customer_pwd' => ''])))
        ->toThrow(ShippingException::class);
});

test('jtexpress tracks with normalized events', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response([
            'code' => '1',
            'msg' => 'success',
            'data' => [[
                'billCode' => 'JT1000000001',
                'details' => [
                    ['scanType' => 'DELIVERED', 'desc' => 'Signed by customer', 'scanTime' => '2026-01-02 10:00:00', 'scanNetworkName' => 'Cairo Hub'],
                    ['scanType' => 'PICKED UP', 'desc' => 'Picked up', 'scanTime' => '2026-01-01 10:00:00', 'scanNetworkName' => 'Giza Hub'],
                ],
            ]],
        ], 200),
    ]);

    $tracked = $driver->track('JT1000000001', jtexpressConnection());

    expect($tracked->trackingNumber)->toBe('JT1000000001')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events[0]->location)->toBe('Cairo Hub')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->raw)->not->toHaveKey('private_key');

    [$request] = $http->recorded()[0];
    $biz = jtexpressBizContent($request);

    expect($biz['billCodes'] ?? null)->toBe('JT1000000001');
});

test('jtexpress throws for unknown tracking numbers', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200),
    ]);

    expect(fn () => $driver->track('UNKNOWN', jtexpressConnection()))
        ->toThrow(ShippingException::class);
});

test('jtexpress label fetches print order', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response([
            'code' => '1',
            'msg' => 'success',
            'data' => ['base64' => base64_encode('%PDF-1.4 label')],
        ], 200),
    ]);

    $label = $driver->label('JT1000000001', jtexpressConnection());

    expect($label->shipmentId)->toBe('JT1000000001')
        ->and($label->contents)->toBe(base64_encode('%PDF-1.4 label'))
        ->and($label->base64Encoded)->toBeTrue();

    [$request] = $http->recorded()[0];
    $biz = jtexpressBizContent($request);

    expect($biz['billCode'] ?? null)->toBe('JT1000000001');
});

test('jtexpress label supports url-only responses', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response([
            'code' => '1',
            'msg' => 'success',
            'data' => ['url' => 'https://example.test/label.pdf'],
        ], 200),
    ]);

    $label = $driver->label('JT1000000001', jtexpressConnection());

    expect($label->url)->toBe('https://example.test/label.pdf');
});

test('jtexpress label throws when empty', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200),
    ]);

    expect(fn () => $driver->label('JT1000000001', jtexpressConnection()))
        ->toThrow(ShippingException::class);
});

test('jtexpress cancels by txlogistic id', function () {
    [$http, $driver] = jtexpressDriverWithFake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => []], 200),
    ]);

    expect($driver->cancel('ORD-42', jtexpressConnection()))->toBeTrue();

    [$request] = $http->recorded()[0];
    $biz = jtexpressBizContent($request);

    expect($biz['txlogisticId'] ?? null)->toBe('ORD-42')
        ->and($biz['customerCode'] ?? null)->toBe('J0099999999');
});

test('jtexpress supports return and exchange via express type metadata', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(['code' => '1', 'msg' => 'success', 'data' => ['billCode' => 'JT-RET-1', 'txlogisticId' => 'ORD-42']], 200),
    ]);

    $driver = JtExpressDriver::fromConnection($http, jtexpressConnection());

    $driver->createShipment(jtexpressDraft(['express_type' => 'DR']), jtexpressConnection());
    [$request] = $http->recorded()[0];

    expect(jtexpressBizContent($request)['expressType'] ?? null)->toBe('DR');
});

test('jtexpress declares honest capabilities', function () {
    $driver = JtExpressDriver::fromConnection(new Factory, jtexpressConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->rates)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse()
        ->and($caps->pickup)->toBeFalse();
});
