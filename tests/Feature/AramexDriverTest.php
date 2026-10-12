<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Drivers\Aramex\AramexDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function aramexConnection(string $id = 'conn_aramex_1', ?array $credentials = null): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'aramex',
        mode: ConnectionMode::Merchant,
        environment: Environment::Sandbox,
        credentials: $credentials ?? [
            'username' => 'testingapi@aramex.com',
            'password' => 'R123456789$r',
            'account_number' => '20016',
            'account_pin' => '331421',
            'account_entity' => 'AMM',
            'account_country_code' => 'JO',
        ],
        merchantId: 'merchant_7',
    );
}

function aramexDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG', phone: '01011111111'),
        destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 200, 'currency' => 'EGP', 'product_group' => 'DOM', 'product_type' => 'OND'], $metadata),
    );
}

function aramexDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, AramexDriver::fromConnection($http, aramexConnection())];
}

function aramexEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function aramexCreateSuccessXml(string $awb = '47123456789'): string
{
    return aramexEnvelope(
        '<CreateShipmentsResponse xmlns="http://ws.aramex.net/shippingapi.v1/">'
        .'<CreateShipmentsResult><HasErrors>false</HasErrors>'
        .'<Shipments><ProcessedShipment>'
        .'<ID>'.$awb.'</ID><Reference1>ORD-42</Reference1><HasErrors>false</HasErrors>'
        .'<ShipmentLabel><LabelURL>https://ws.aramex.net/label/'.$awb.'.pdf</LabelURL></ShipmentLabel>'
        .'</ProcessedShipment></Shipments>'
        .'</CreateShipmentsResult></CreateShipmentsResponse>'
    );
}

function aramexTrackSuccessXml(string $awb = '47123456789'): string
{
    return aramexEnvelope(
        '<TrackShipmentsResponse xmlns="http://ws.aramex.net/shippingapi.v1/">'
        .'<TrackShipmentsResult><HasErrors>false</HasErrors>'
        .'<TrackingResults><KeyValueOfstringArrayOfTrackingResultmFAkxlpY>'
        .'<Key>'.$awb.'</Key><Value>'
        .'<TrackingResult><UpdateCode>SH003</UpdateCode><UpdateDescription>Collected from shipper</UpdateDescription>'
        .'<UpdateDateTime>2026-01-01T10:00:00</UpdateDateTime><UpdateLocation>Cairo</UpdateLocation></TrackingResult>'
        .'<TrackingResult><UpdateCode>Delivered</UpdateCode><UpdateDescription>Delivered to consignee</UpdateDescription>'
        .'<UpdateDateTime>2026-01-02T10:00:00</UpdateDateTime><UpdateLocation>Giza</UpdateLocation></TrackingResult>'
        .'</Value></KeyValueOfstringArrayOfTrackingResultmFAkxlpY></TrackingResults>'
        .'</TrackShipmentsResult></TrackShipmentsResponse>'
    );
}

function aramexLabelSuccessXml(string $awb = '47123456789'): string
{
    return aramexEnvelope(
        '<PrintLabelResponse xmlns="http://ws.aramex.net/shippingapi.v1/">'
        .'<PrintLabelResult><HasErrors>false</HasErrors>'
        .'<ShipmentLabel><LabelURL>https://ws.aramex.net/label/'.$awb.'.pdf</LabelURL>'
        .'<LabelFileContents>'.base64_encode('%PDF-1.4 aramex-label').'</LabelFileContents></ShipmentLabel>'
        .'</PrintLabelResult></PrintLabelResponse>'
    );
}

function aramexRateSuccessXml(): string
{
    return aramexEnvelope(
        '<CalculateRateResponse xmlns="http://ws.aramex.net/shippingapi.v1/">'
        .'<CalculateRateResult><HasErrors>false</HasErrors>'
        .'<TotalAmount><Value>145.5</Value><CurrencyCode>EGP</CurrencyCode></TotalAmount>'
        .'</CalculateRateResult></CalculateRateResponse>'
    );
}

function aramexHasErrorsXml(string $operation, string $result, string $code = 'ERR01', string $message = 'Invalid account'): string
{
    return aramexEnvelope(
        '<'.$operation.' xmlns="http://ws.aramex.net/shippingapi.v1/">'
        .'<'.$result.'><HasErrors>true</HasErrors>'
        .'<Notifications><Notification><Code>'.$code.'</Code><Message>'.$message.'</Message></Notification></Notifications>'
        .'</'.$result.'></'.$operation.'>'
    );
}

function aramexFaultXml(string $message = 'Invalid ClientInfo'): string
{
    return aramexEnvelope(
        '<soap:Fault><faultcode>soap:Server</faultcode><faultstring>'.$message.'</faultstring></soap:Fault>'
    );
}

test('aramex creates a shipment over raw soap with clientinfo auth', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexCreateSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $result = $driver->createShipment(aramexDraft(), aramexConnection());

    expect($result->id)->toBe('47123456789')
        ->and($result->trackingNumber)->toBe('47123456789')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('aramex')
        ->and($result->labelUrl)->toBe('https://ws.aramex.net/label/47123456789.pdf')
        ->and($result->raw)->not->toHaveKey('password')
        ->and($result->raw)->not->toHaveKey('account_pin');

    [$request] = $http->recorded()[0];

    expect($request->header('Content-Type')[0])->toContain('text/xml')
        ->and($request->header('SOAPAction')[0])->toContain('CreateShipments')
        ->and($request->body())->toContain('<arx:CreateShipments')
        ->and($request->body())->toContain('<arx:AccountNumber>20016</arx:AccountNumber>')
        ->and($request->body())->toContain('<arx:ProductGroup>DOM</arx:ProductGroup>')
        ->and($request->body())->toContain('<arx:ProductType>OND</arx:ProductType>')
        ->and($request->body())->toContain('<arx:Services>CODS</arx:Services>')
        ->and($request->url())->toContain('ws.dev.aramex.net');
});

test('aramex create surfaces haserrors notifications', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexHasErrorsXml('CreateShipmentsResponse', 'CreateShipmentsResult', 'ERR01', 'Invalid account'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->createShipment(aramexDraft(), aramexConnection()))
        ->toThrow(ShippingException::class, 'Invalid account');
});

test('aramex maps soap faults to carrier failures', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexFaultXml('Invalid ClientInfo'), 500, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->createShipment(aramexDraft(), aramexConnection()))
        ->toThrow(ShippingException::class, 'Invalid ClientInfo');
});

test('aramex requires phone on both parties', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexCreateSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
        destination: new Address(name: 'No Phone', line1: '12 Nile St', city: 'Giza', countryCode: 'EG'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, aramexConnection()))
        ->toThrow(ShippingException::class, 'phone');
});

test('aramex requires account credentials before sending', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(aramexCreateSuccessXml(), 200)]);

    $driver = AramexDriver::fromConnection($http, aramexConnection(credentials: []));

    expect(fn () => $driver->createShipment(aramexDraft(), aramexConnection(credentials: [])))
        ->toThrow(ShippingException::class, 'Missing Aramex credentials');
});

test('aramex tracks with normalized events', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexTrackSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = $driver->track('47123456789', aramexConnection());

    expect($tracked->trackingNumber)->toBe('47123456789')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->events[0]->location)->toBe('Cairo')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->raw)->not->toHaveKey('password');

    [$request] = $http->recorded()[0];

    expect($request->body())->toContain('<arx:TrackShipments')
        ->and($request->body())->toContain('47123456789')
        ->and($request->url())->toContain('/Tracking/');
});

test('aramex throws shipment not found for unknown awbs', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexEnvelope(
            '<TrackShipmentsResponse xmlns="http://ws.aramex.net/shippingapi.v1/">'
            .'<TrackShipmentsResult><HasErrors>false</HasErrors><TrackingResults />'
            .'</TrackShipmentsResult></TrackShipmentsResponse>'
        ), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->track('UNKNOWN', aramexConnection()))
        ->toThrow(ShippingException::class);
});

test('aramex prints a label with url and contents', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexLabelSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $label = $driver->label('47123456789', aramexConnection());

    expect($label->shipmentId)->toBe('47123456789')
        ->and($label->contents)->toBe(base64_encode('%PDF-1.4 aramex-label'))
        ->and($label->base64Encoded)->toBeTrue()
        ->and($label->url)->toBe('https://ws.aramex.net/label/47123456789.pdf');

    [$request] = $http->recorded()[0];

    expect($request->body())->toContain('<arx:PrintLabel')
        ->and($request->body())->toContain('<arx:ShipmentNumber>47123456789</arx:ShipmentNumber>');
});

test('aramex quotes a rate via calculaterate', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexRateSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $quotes = $driver->rates(aramexDraft(), aramexConnection());

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->amount)->toBe(145.5)
        ->and($quotes[0]->currency)->toBe('EGP')
        ->and($quotes[0]->carrierCode)->toBe('aramex')
        ->and($quotes[0]->serviceCode)->toBe('DOM:OND');

    [$request] = $http->recorded()[0];

    expect($request->body())->toContain('<arx:CalculateRate')
        ->and($request->body())->toContain('<arx:PreferredCurrencyCode>EGP</arx:PreferredCurrencyCode>')
        ->and($request->url())->toContain('/RateCalculator/');
});

test('aramex rate surfaces haserrors notifications', function () {
    [$http, $driver] = aramexDriverWithFake([
        '*' => Factory::response(aramexHasErrorsXml('CalculateRateResponse', 'CalculateRateResult', 'R001', 'Origin address invalid'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->rates(aramexDraft(), aramexConnection()))
        ->toThrow(ShippingException::class, 'Origin address invalid');
});

test('aramex declares honest capabilities without cancellation', function () {
    $driver = AramexDriver::fromConnection(new Factory, aramexConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->rates)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->cancel)->toBeFalse()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->webhooks)->toBeFalse()
        ->and($driver)->not->toBeInstanceOf(SupportsCancellation::class);
});
