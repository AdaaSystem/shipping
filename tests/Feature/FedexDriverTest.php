<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Fedex\FedexClient;
use Adaa\Shipping\Drivers\Fedex\FedexDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function fedexConnection(string $id = 'conn_fedex_1', ?array $credentials = null): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'fedex',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: $credentials ?? [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'account_number' => '123456789',
        ],
        merchantId: 'merchant_7',
    );
}

function fedexDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG', phone: '01011111111', postalCode: '11511'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Irving', state: 'TX', countryCode: 'US', phone: '01000000000', postalCode: '75063'),
        parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt', lengthCm: 12, widthCm: 12, heightCm: 12)],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 200, 'currency' => 'USD'], $metadata),
    );
}

function fedexTokenJson(): array
{
    return ['access_token' => 'test-oauth-token', 'token_type' => 'bearer', 'expires_in' => 3599, 'scope' => 'CXS SECURE'];
}

function fedexCreateJson(string $tracking = '794699999999', ?string $encodedLabel = null): array
{
    return [
        'transactionId' => '99ba99f9-9999-99f9-a99d-9a9c9e9ac99a',
        'output' => [
            'transactionShipments' => [[
                'masterTrackingNumber' => $tracking,
                'serviceType' => 'INTERNATIONAL_PRIORITY',
                'shipDatestamp' => '2026-07-16',
                'serviceName' => 'FedEx International Priority',
                'pieceResponses' => [[
                    'masterTrackingNumber' => $tracking,
                    'trackingNumber' => $tracking,
                    'packageDocuments' => [[
                        'contentType' => 'LABEL',
                        'docType' => 'PDF',
                        'encodedLabel' => $encodedLabel ?? base64_encode('%PDF-1.4 fedex-label'),
                    ]],
                    'currency' => 'USD',
                    'baseRateAmount' => 17.4,
                ]],
            ]],
        ],
    ];
}

function fedexRateJson(): array
{
    return [
        'output' => [
            'rateReplyDetails' => [
                [
                    'serviceType' => 'INTERNATIONAL_PRIORITY',
                    'serviceName' => 'FedEx International Priority',
                    'packagingType' => 'YOUR_PACKAGING',
                    'ratedShipmentDetails' => [[
                        'totalNetCharge' => ['currency' => 'USD', 'amount' => 42.5],
                    ]],
                ],
                [
                    'serviceType' => 'FEDEX_GROUND',
                    'serviceName' => 'FedEx Ground',
                    'packagingType' => 'YOUR_PACKAGING',
                    'ratedShipmentDetails' => [[
                        'totalNetCharge' => ['currency' => 'USD', 'amount' => 17.4],
                    ]],
                ],
            ],
        ],
    ];
}

function fedexTrackJson(string $tracking = '794699999999'): array
{
    return [
        'transactionId' => '6ff3eeba-5e2a-4459-9aeb-2761bb109511',
        'output' => [
            'completeTrackResults' => [[
                'trackingNumber' => $tracking,
                'trackResults' => [[
                    'trackingNumberInfo' => ['trackingNumber' => $tracking, 'carrierCode' => 'FDXE'],
                    'latestStatusDetail' => [
                        'code' => 'DL',
                        'derivedCode' => 'DL',
                        'statusByLocale' => 'Delivered',
                        'description' => 'Delivered',
                        'scanLocation' => ['city' => 'Irving', 'countryName' => 'United States'],
                    ],
                    'scanEvents' => [
                        [
                            'date' => '2026-07-15T10:00:00-05:00',
                            'eventType' => 'PU',
                            'eventDescription' => 'Picked up',
                            'derivedStatusCode' => 'PU',
                            'scanLocation' => ['city' => 'Memphis', 'countryName' => 'United States'],
                        ],
                        [
                            'date' => '2026-07-16T10:00:00-05:00',
                            'eventType' => 'DL',
                            'eventDescription' => 'Delivered',
                            'derivedStatusCode' => 'DL',
                            'scanLocation' => ['city' => 'Irving', 'countryName' => 'United States'],
                        ],
                    ],
                ]],
            ]],
        ],
    ];
}

function fedexDriverWithFake(array|callable $stub, ?ShippingConnectionContext $connection = null): array
{
    FedexClient::clearTokenCache();
    $http = new Factory;
    $http->fake($stub);
    $connection ??= fedexConnection();

    return [$http, FedexDriver::fromConnection($http, $connection), $connection];
}

beforeEach(function () {
    FedexClient::clearTokenCache();
});

test('fedex fetches oauth token with client credentials and uses bearer auth', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexCreateJson(), 200);
    });

    $result = $driver->createShipment(fedexDraft(), $connection);

    expect($result->trackingNumber)->toBe('794699999999');

    $recorded = $http->recorded();
    expect($recorded)->toHaveCount(2);

    [$tokenRequest, $shipRequest] = $recorded;

    expect($tokenRequest[0]->url())->toContain('/oauth/token')
        ->and($shipRequest[0]->header('Authorization'))->toBe(['Bearer test-oauth-token'])
        ->and($shipRequest[0]->url())->toContain('apis-sandbox.fedex.com')
        ->and($shipRequest[0]->url())->toContain('/ship/v1/shipments');

    $tokenBody = $tokenRequest[0]->data();

    expect($tokenBody['grant_type'] ?? null)->toBe('client_credentials')
        ->and($tokenBody['client_id'] ?? null)->toBe('test-client-id');

    $shipPayload = $shipRequest[0]->data();

    expect($shipPayload['labelResponseOptions'] ?? null)->toBe('LABEL')
        ->and($shipPayload['accountNumber']['value'] ?? null)->toBe('123456789')
        ->and($shipPayload['requestedShipment']['serviceType'] ?? null)->toBe('INTERNATIONAL_PRIORITY')
        ->and($shipPayload['requestedShipment']['shipmentSpecialServices']['specialServiceTypes'] ?? null)->toBe(['COD']);
});

test('fedex reuses per-connection token without refetching', function () {
    $calls = 0;

    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) use (&$calls) {
        if (str_contains($request->url(), '/oauth/token')) {
            $calls++;

            return Factory::response(fedexTokenJson(), 200);
        }

        if (str_contains($request->url(), '/track/v1/trackingnumbers')) {
            return Factory::response(fedexTrackJson(), 200);
        }

        return Factory::response(fedexCreateJson(), 200);
    });

    $driver->createShipment(fedexDraft(), $connection);
    $driver->track('794699999999', $connection);

    expect($calls)->toBe(1);
});

test('fedex isolates tokens per connection via cache key', function () {
    $tokenCalls = [];

    FedexClient::clearTokenCache();
    $http = new Factory;
    $http->fake(function ($request) use (&$tokenCalls) {
        if (str_contains($request->url(), '/oauth/token')) {
            $tokenCalls[] = $request->data()['client_id'] ?? null;

            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexCreateJson(), 200);
    });

    $connectionA = fedexConnection('conn_fedex_A');
    $connectionB = fedexConnection('conn_fedex_B');

    expect($connectionA->cacheKey('fedex'))->not->toBe($connectionB->cacheKey('fedex'));

    $driverA = FedexDriver::fromConnection($http, $connectionA);
    $driverB = FedexDriver::fromConnection($http, $connectionB);

    $driverA->createShipment(fedexDraft(), $connectionA);
    $driverB->createShipment(fedexDraft(), $connectionB);

    expect($tokenCalls)->toHaveCount(2);
});

test('fedex creates a shipment and preserves label bytes', function () {
    $encoded = base64_encode('%PDF-1.4 fedex-label');

    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexCreateJson(encodedLabel: base64_encode('%PDF-1.4 fedex-label')), 200);
    });

    $result = $driver->createShipment(fedexDraft(), $connection);

    expect($result->id)->toBe('794699999999')
        ->and($result->trackingNumber)->toBe('794699999999')
        ->and($result->carrier)->toBe('fedex')
        ->and($result->raw['output']['transactionShipments'][0]['pieceResponses'][0]['packageDocuments'][0]['encodedLabel'] ?? null)->toBe($encoded)
        ->and($result->raw)->not->toHaveKey('client_secret')
        ->and($result->raw)->not->toHaveKey('client_id');

    $label = $driver->label('794699999999', $connection);

    expect($label->shipmentId)->toBe('794699999999')
        ->and($label->contents)->toBe($encoded)
        ->and($label->base64Encoded)->toBeTrue();
});

test('fedex label falls back to tracking url without stored bytes', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexTrackJson(), 200);
    });

    $label = $driver->label('794699999999', $connection);

    expect($label->shipmentId)->toBe('794699999999')
        ->and($label->contents)->toBe('')
        ->and($label->base64Encoded)->toBeFalse()
        ->and($label->url)->toContain('794699999999');
});

test('fedex quotes rates from rateReplyDetails', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexRateJson(), 200);
    });

    $quotes = $driver->rates(fedexDraft(), $connection);

    expect($quotes)->toHaveCount(2)
        ->and($quotes[0]->serviceCode)->toBe('INTERNATIONAL_PRIORITY')
        ->and($quotes[0]->amount)->toBe(42.5)
        ->and($quotes[0]->currency)->toBe('USD')
        ->and($quotes[0]->carrierCode)->toBe('fedex')
        ->and($quotes[1]->serviceCode)->toBe('FEDEX_GROUND');

    [$tokenRecorded, $rateRecorded] = $http->recorded();

    expect($rateRecorded[0]->url())->toContain('/rate/v1/rates/quotes')
        ->and($rateRecorded[0]->data()['accountNumber']['value'] ?? null)->toBe('123456789');
});

test('fedex tracks with scanEvents and derivedStatusCode', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexTrackJson(), 200);
    });

    $tracked = $driver->track('794699999999', $connection);

    expect($tracked->trackingNumber)->toBe('794699999999')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->events[0]->location)->toBe('Memphis')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->raw)->not->toHaveKey('client_secret');

    [$tokenRecorded, $trackRecorded] = $http->recorded();
    $trackPayload = $trackRecorded[0]->data();

    expect($trackRecorded[0]->url())->toContain('/track/v1/trackingnumbers')
        ->and($trackPayload['trackingInfo'][0]['trackingNumberInfo']['trackingNumber'] ?? null)->toBe('794699999999')
        ->and($trackPayload['includeDetailedScans'] ?? null)->toBeTrue();
});

test('fedex maps carrier errors with code and message', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response([
            'errors' => [['code' => 'SHIPMENT.INVALID', 'message' => 'Invalid postal code']],
        ], 400);
    });

    expect(fn () => $driver->createShipment(fedexDraft(), $connection))
        ->toThrow(ShippingException::class, 'SHIPMENT.INVALID: Invalid postal code');
});

test('fedex throws shipment not found for empty track results', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(['output' => ['completeTrackResults' => []]], 200);
    });

    expect(fn () => $driver->track('UNKNOWN', $connection))
        ->toThrow(ShippingException::class);
});

test('fedex requires credentials before sending', function () {
    FedexClient::clearTokenCache();
    $http = new Factory;
    $http->fake(['*' => Factory::response(fedexCreateJson(), 200)]);

    $empty = fedexConnection(credentials: []);
    $driver = FedexDriver::fromConnection($http, $empty);

    expect(fn () => $driver->createShipment(fedexDraft(), $empty))
        ->toThrow(ShippingException::class, 'account_number');

    $noAuth = fedexConnection(credentials: ['account_number' => '123456789']);
    $noAuthDriver = FedexDriver::fromConnection($http, $noAuth);

    expect(fn () => $noAuthDriver->createShipment(fedexDraft(), $noAuth))
        ->toThrow(ShippingException::class, 'client_id');
});

test('fedex requires recipient phone', function () {
    [$http, $driver, $connection] = fedexDriverWithFake([
        '*' => Factory::response(fedexCreateJson(), 200),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No Phone', line1: '12 Nile St', city: 'Irving', countryCode: 'US'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, $connection))
        ->toThrow(ShippingException::class, 'phone');
});

test('fedex return swaps parties through the ship payload', function () {
    [$http, $driver, $connection] = fedexDriverWithFake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Factory::response(fedexTokenJson(), 200);
        }

        return Factory::response(fedexCreateJson(), 200);
    });

    $result = $driver->createReturn(fedexDraft(), $connection);

    expect($result->status)->toBe(ShipmentStatus::Returned)
        ->and($result->trackingNumber)->toBe('794699999999');

    $shipRequest = $http->recorded()[1][0];
    $payload = $shipRequest->data();

    expect($payload['requestedShipment']['shipper']['address']['city'] ?? null)->toBe('Irving')
        ->and($payload['requestedShipment']['recipients'][0]['address']['city'] ?? null)->toBe('Cairo');
});

test('fedex declares honest capabilities', function () {
    FedexClient::clearTokenCache();
    $driver = FedexDriver::fromConnection(new Factory, fedexConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->rates)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->cancel)->toBeFalse()
        ->and($caps->pickup)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse();
});
