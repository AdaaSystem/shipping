<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Ups\UpsClient;
use Adaa\Shipping\Drivers\Ups\UpsDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

function upsConnection(string $id = 'conn_ups_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'ups',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: array_merge([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'account_number' => 'A12345',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function upsDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Acme Corp', line1: '100 Main St', city: 'Louisville', countryCode: 'US', state: 'KY', postalCode: '40213', phone: '5025550100'),
        destination: new Address(name: 'Customer Inc', line1: '500 Oak Ave', city: 'Atlanta', countryCode: 'US', state: 'GA', postalCode: '30301', phone: '4045550100'),
        parcels: [new Parcel(weightKg: 2.5, description: 'Books', lengthCm: 30.0, widthCm: 20.0, heightCm: 15.0)],
        reference: 'ORD-42',
        metadata: $metadata,
    );
}

function upsTokenJson(string $token = 'test-ups-token'): array
{
    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 14400];
}

function upsShipSuccessJson(string $tracking = '1Z12345E8791315509'): array
{
    return [
        'ShipmentResponse' => [
            'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
            'ShipmentResults' => [
                'ShipmentIdentificationNumber' => $tracking,
                'ShipmentCharges' => ['TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '18.75']],
                'PackageResults' => [[
                    'TrackingNumber' => $tracking,
                    'ServiceCode' => '11',
                    'ShippingLabel' => [
                        'GraphicImage' => base64_encode('%PDF-1.4 ups-label'),
                        'HTMLImage' => '<html>ups-label</html>',
                    ],
                ]],
            ],
        ],
    ];
}

function upsShopSuccessJson(): array
{
    return [
        'RateResponse' => [
            'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
            'RatedShipment' => [
                [
                    'Service' => ['Code' => '11', 'Description' => 'UPS Standard'],
                    'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '18.75'],
                    'GuaranteedDelivery' => ['BusinessDaysInTransit' => '3'],
                ],
                [
                    'Service' => ['Code' => '07', 'Description' => 'UPS Worldwide Express'],
                    'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '42.10'],
                    'GuaranteedDelivery' => ['BusinessDaysInTransit' => '1', 'DeliveryByTime' => '10:30'],
                ],
            ],
        ],
    ];
}

function upsTrackSuccessJson(string $tracking = '1Z12345E8791315509'): array
{
    return [
        'trackResponse' => [
            'shipment' => [[
                'inquiryNumber' => $tracking,
                'package' => [[
                    'trackingNumber' => $tracking,
                    'currentStatus' => ['code' => 'O', 'description' => 'Out For Delivery'],
                    'activity' => [
                        [
                            'location' => ['address' => ['city' => 'Atlanta', 'stateProvince' => 'GA', 'countryCode' => 'US']],
                            'status' => ['type' => 'O', 'description' => 'Out For Delivery'],
                            'date' => '20260102',
                            'time' => '083000',
                        ],
                        [
                            'location' => ['address' => ['city' => 'Louisville', 'stateProvince' => 'KY', 'countryCode' => 'US']],
                            'status' => ['type' => 'I', 'description' => 'In Transit'],
                            'date' => '20260101',
                            'time' => '120000',
                        ],
                    ],
                ]],
            ]],
        ],
    ];
}

function upsLabelRecoveryJson(string $tracking = '1Z12345E8791315509'): array
{
    return [
        'LabelRecoveryResponse' => [
            'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
            'ShipmentIdentificationNumber' => $tracking,
            'LabelResults' => [
                'TrackingNumber' => $tracking,
                'LabelImage' => [
                    'GraphicImage' => base64_encode('%PDF-1.4 ups-label'),
                    'URL' => 'https://www.ups.com/label/'.$tracking,
                    'LabelImageFormat' => ['Code' => 'PDF'],
                ],
            ],
        ],
    ];
}

function upsVoidSuccessJson(): array
{
    return [
        'VoidShipmentResponse' => [
            'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
            'SummaryResult' => ['Status' => ['Code' => '1', 'Description' => 'Voided']],
        ],
    ];
}

/**
 * @return array{0: Factory, 1: UpsDriver}
 */
function upsDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, UpsDriver::fromConnection($http, upsConnection())];
}

/**
 * @return list<Request>
 */
function upsRecordedFor(Factory $http, string $needle): array
{
    $matched = [];

    foreach ($http->recorded() as $pair) {
        /** @var Request $request */
        $request = $pair[0];

        if (str_contains($request->url(), $needle)) {
            $matched[] = $request;
        }
    }

    return $matched;
}

test('ups creates a shipment with oauth2 bearer auth and preserves label bytes', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsShipSuccessJson(), 200);
    });

    $result = $driver->createShipment(upsDraft(), upsConnection());

    expect($result->id)->toBe('1Z12345E8791315509')
        ->and($result->trackingNumber)->toBe('1Z12345E8791315509')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('ups')
        ->and($result->rate)->toBe(18.75)
        ->and($result->currency)->toBe('USD')
        ->and($result->labelUrl)->toContain('1Z12345E8791315509')
        ->and($result->raw)->not->toHaveKey('client_secret')
        ->and($result->raw)->not->toHaveKey('client_id');

    // Label bytes survive sanitization inside raw (the shipbridge-ups gap fix).
    expect(UpsDriver::extractLabelContents($result->raw))->toBe(base64_encode('%PDF-1.4 ups-label'));

    $tokenRequests = upsRecordedFor($http, 'oauth/token');
    $shipRequests = upsRecordedFor($http, '/ship');

    expect($tokenRequests)->toHaveCount(1)
        ->and($shipRequests)->toHaveCount(1);

    $tokenAuth = $tokenRequests[0]->header('Authorization')[0] ?? '';

    expect($tokenAuth)->toStartWith('Basic ')
        ->and($tokenRequests[0]->body())->toContain('grant_type=client_credentials');

    $shipAuth = $shipRequests[0]->header('Authorization')[0] ?? '';

    expect($shipAuth)->toBe('Bearer test-ups-token')
        ->and($shipRequests[0]->url())->toContain('wwwcie.ups.com')
        ->and($shipRequests[0]->header('transactionSrc'))->toBe(['adaa-shipping-ups']);

    $shipment = $shipRequests[0]->data()['ShipmentRequest']['Shipment'] ?? [];

    expect($shipment['Service']['Code'] ?? null)->toBe('11')
        ->and($shipment['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber'] ?? null)->toBe('A12345')
        ->and($shipment['Shipper']['ShipperNumber'] ?? null)->toBe('A12345')
        ->and($shipRequests[0]->data()['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'] ?? null)->toBe('GIF');
});

test('ups create sends negotiated rates indicator when requested', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsShipSuccessJson(), 200);
    });

    $driver->createShipment(upsDraft(['negotiated_rates' => true]), upsConnection());

    $shipRequests = upsRecordedFor($http, '/ship');

    expect($shipRequests)->toHaveCount(1)
        ->and($shipRequests[0]->data()['ShipmentRequest']['Shipment']['ShipmentRatingOptions']['NegotiatedRatesIndicator'] ?? null)->toBe('Y');
});

test('ups create requires an account number', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(upsShipSuccessJson(), 200)]);

    $connection = upsConnection(credentials: ['client_id' => 'id', 'client_secret' => 'secret', 'account_number' => '']);
    $driver = UpsDriver::fromConnection($http, $connection);

    expect(fn () => $driver->createShipment(upsDraft(), $connection))
        ->toThrow(ShippingException::class, 'account_number');
});

test('ups create requires a shipto phone', function () {
    [$http, $driver] = upsDriverWithFake([
        '*' => Factory::response(upsShipSuccessJson(), 200),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Acme', line1: '100 Main St', city: 'Louisville', countryCode: 'US', postalCode: '40213', phone: '5025550100'),
        destination: new Address(name: 'No Phone', line1: '500 Oak Ave', city: 'Atlanta', countryCode: 'US', postalCode: '30301'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, upsConnection()))
        ->toThrow(ShippingException::class, 'phone');
});

test('ups create surfaces carrier error envelopes', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(['response' => ['errors' => [['code' => '250002', 'message' => 'Invalid shipper number']]]], 400);
    });

    expect(fn () => $driver->createShipment(upsDraft(), upsConnection()))
        ->toThrow(ShippingException::class, 'Invalid shipper number');
});

test('ups quotes rates via shop with multiple services', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsShopSuccessJson(), 200);
    });

    $quotes = $driver->rates(upsDraft(), upsConnection());

    expect($quotes)->toHaveCount(2)
        ->and($quotes[0]->serviceCode)->toBe('11')
        ->and($quotes[0]->serviceName)->toBe('UPS Standard')
        ->and($quotes[0]->amount)->toBe(18.75)
        ->and($quotes[0]->currency)->toBe('USD')
        ->and($quotes[0]->estimatedDelivery)->toContain('3 business days')
        ->and($quotes[0]->carrierCode)->toBe('ups')
        ->and($quotes[1]->serviceCode)->toBe('07')
        ->and($quotes[1]->amount)->toBe(42.10)
        ->and($quotes[1]->estimatedDelivery)->toContain('10:30');

    $rateRequests = upsRecordedFor($http, '/rating/');

    expect($rateRequests)->toHaveCount(1)
        ->and($rateRequests[0]->url())->toContain('/Shop')
        ->and($rateRequests[0]->data()['RateRequest']['Request']['RequestOption'] ?? null)->toBe('Shop');
});

test('ups rate surfaces carrier errors', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(['response' => ['errors' => [['code' => '111210', 'message' => 'Invalid postal code']]]], 400);
    });

    expect(fn () => $driver->rates(upsDraft(), upsConnection()))
        ->toThrow(ShippingException::class, 'Invalid postal code');
});

test('ups tracks with normalized activities', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsTrackSuccessJson(), 200);
    });

    $tracked = $driver->track('1Z12345E8791315509', upsConnection());

    expect($tracked->trackingNumber)->toBe('1Z12345E8791315509')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events[0]->location)->toBe('Atlanta, GA, US')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::InTransit)
        ->and($tracked->raw)->not->toHaveKey('client_secret');

    $trackRequests = upsRecordedFor($http, '/details/');

    expect($trackRequests)->toHaveCount(1)
        ->and($trackRequests[0]->url())->toContain('1Z12345E8791315509');
});

test('ups maps unknown tracking numbers to shipment not found', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(['trackResponse' => ['shipment' => []]], 200);
    });

    expect(fn () => $driver->track('UNKNOWN', upsConnection()))
        ->toThrow(ShippingException::class, 'Shipment not found');
});

test('ups maps track error envelopes to shipment not found', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(['response' => ['errors' => [['code' => '152123', 'message' => 'No tracking information available']]]], 404);
    });

    expect(fn () => $driver->track('1Z0000000000000000', upsConnection()))
        ->toThrow(ShippingException::class, 'Shipment not found');
});

test('ups recovers a label with graphic image bytes', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsLabelRecoveryJson(), 200);
    });

    $label = $driver->label('1Z12345E8791315509', upsConnection());

    expect($label->shipmentId)->toBe('1Z12345E8791315509')
        ->and($label->contents)->toBe(base64_encode('%PDF-1.4 ups-label'))
        ->and($label->base64Encoded)->toBeTrue()
        ->and($label->url)->toBe('https://www.ups.com/label/1Z12345E8791315509');

    $labelRequests = upsRecordedFor($http, '/labels/');

    expect($labelRequests)->toHaveCount(1)
        ->and($labelRequests[0]->url())->toContain('/recovery')
        ->and($labelRequests[0]->data()['LabelRecoveryRequest']['TrackingNumber'] ?? null)->toBe('1Z12345E8791315509');
});

test('ups label throws when recovery is empty', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(['LabelRecoveryResponse' => ['LabelResults' => []]], 200);
    });

    expect(fn () => $driver->label('1Z12345E8791315509', upsConnection()))
        ->toThrow(ShippingException::class);
});

test('ups voids a shipment via delete', function () {
    [$http, $driver] = upsDriverWithFake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Factory::response(upsTokenJson(), 200);
        }

        return Factory::response(upsVoidSuccessJson(), 200);
    });

    expect($driver->cancel('1Z12345E8791315509', upsConnection()))->toBeTrue();

    $voidRequests = upsRecordedFor($http, '/void/cancel/');

    expect($voidRequests)->toHaveCount(1)
        ->and($voidRequests[0]->url())->toContain('/void/cancel/1Z12345E8791315509');
});

test('ups oauth tokens are per-connection and memoized', function () {
    $http = new Factory;
    $http->fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            $auth = $request->header('Authorization')[0] ?? 'none';

            return Factory::response(upsTokenJson('tok-'.md5($auth)), 200);
        }

        if (str_contains($request->url(), '/details/')) {
            return Factory::response(upsTrackSuccessJson(), 200);
        }

        return Factory::response(upsShipSuccessJson(), 200);
    });

    $connectionA = upsConnection('conn_ups_A', ['client_id' => 'id-A', 'client_secret' => 'secret-A', 'account_number' => 'A111']);
    $connectionB = upsConnection('conn_ups_B', ['client_id' => 'id-B', 'client_secret' => 'secret-B', 'account_number' => 'B222']);

    // Per-connection cache keys never collide (no global token bucket).
    expect($connectionA->cacheKey('ups'))->toBe('shipping:token:conn_ups_A:ups')
        ->and($connectionB->cacheKey('ups'))->toBe('shipping:token:conn_ups_B:ups')
        ->and($connectionA->cacheKey('ups'))->not->toBe($connectionB->cacheKey('ups'));

    $clientA = new UpsClient($http, ['token_cache_key' => $connectionA->cacheKey('ups')] + $connectionA->credentials);

    expect($clientA->tokenCacheKey())->toBe($connectionA->cacheKey('ups'));

    $driverA = UpsDriver::fromConnection($http, $connectionA);
    $driverB = UpsDriver::fromConnection($http, $connectionB);

    $driverA->createShipment(upsDraft(), $connectionA);
    $driverB->createShipment(upsDraft(), $connectionB);

    $shipRequests = upsRecordedFor($http, '/ship');
    $authA = $shipRequests[0]->header('Authorization')[0] ?? '';
    $authB = $shipRequests[1]->header('Authorization')[0] ?? '';

    expect($shipRequests)->toHaveCount(2)
        ->and($authA)->toStartWith('Bearer ')
        ->and($authB)->toStartWith('Bearer ')
        ->and($authA)->not->toBe($authB);

    // Memoized: a second call on driver A reuses its token (no new OAuth hit).
    $driverA->track('1Z12345E8791315509', $connectionA);

    expect(upsRecordedFor($http, 'oauth/token'))->toHaveCount(2);

    $trackRequests = upsRecordedFor($http, '/details/');

    expect($trackRequests)->toHaveCount(1)
        ->and($trackRequests[0]->header('Authorization')[0] ?? '')->toBe($authA);
});

test('ups declares honest capabilities', function () {
    $driver = UpsDriver::fromConnection(new Factory, upsConnection());
    $caps = $driver->capabilities();

    expect($caps->rates)->toBeTrue()
        ->and($caps->create)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->return)->toBeFalse()
        ->and($caps->exchange)->toBeFalse()
        ->and($caps->pickup)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse();
});
