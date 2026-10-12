<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Smsa\SmsaDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function smsaConnection(string $id = 'conn_smsa_1', array $credentials = ['passkey' => 'test-passkey']): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'smsa',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: $credentials,
        merchantId: 'merchant_7',
    );
}

function smsaDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Warehouse LLC', line1: '1 King Rd', city: 'Riyadh', countryCode: 'SA', phone: '0110000000'),
        destination: new Address(name: 'Customer', line1: '10 Fahad Rd', city: 'Jeddah', countryCode: 'SA', phone: '0555555555'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Clothes')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 50], $metadata),
    );
}

function smsaEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function smsaAddShipSuccessXml(string $awb = '290019315792'): string
{
    return smsaEnvelope(
        '<addShipResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<addShipResult>'.$awb.'</addShipResult></addShipResponse>'
    );
}

function smsaAddShipErrorXml(string $message = 'Failed - Invalid passkey'): string
{
    return smsaEnvelope(
        '<addShipResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<addShipResult>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</addShipResult></addShipResponse>'
    );
}

function smsaFaultXml(string $message = 'Server was unable to process request.'): string
{
    return smsaEnvelope(
        '<soap:Fault><faultcode>soap:Server</faultcode>'
        .'<faultstring>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</faultstring></soap:Fault>'
    );
}

function smsaTrackSuccessXml(): string
{
    return smsaEnvelope(
        '<getTrackResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<getTrackResult><RequestStatus>Success</RequestStatus><TrackDetailsList>'
        .'<TrackDetails><awbNo>290019315792</awbNo><evtDate>2026-01-02T10:00:00</evtDate>'
        .'<Activity>Out for delivery</Activity><Details>Out for delivery</Details><Location>Jeddah</Location></TrackDetails>'
        .'<TrackDetails><awbNo>290019315792</awbNo><evtDate>2026-01-01T10:00:00</evtDate>'
        .'<Activity>Picked up</Activity><Details>Picked up from shipper</Details><Location>Riyadh</Location></TrackDetails>'
        .'</TrackDetailsList></getTrackResult></getTrackResponse>'
    );
}

function smsaTrackEmptyXml(): string
{
    return smsaEnvelope(
        '<getTrackResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<getTrackResult><RequestStatus>Success</RequestStatus><TrackDetailsList /></getTrackResult></getTrackResponse>'
    );
}

function smsaStatusXml(string $status = 'Delivered'): string
{
    return smsaEnvelope(
        '<getStatusResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<getStatusResult>'.htmlspecialchars($status, ENT_XML1, 'UTF-8').'</getStatusResult></getStatusResponse>'
    );
}

function smsaPdfXml(string $contents): string
{
    return smsaEnvelope(
        '<getPDFResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<getPDFResult>'.htmlspecialchars($contents, ENT_XML1, 'UTF-8').'</getPDFResult></getPDFResponse>'
    );
}

function smsaCancelSuccessXml(string $result = 'Success'): string
{
    return smsaEnvelope(
        '<cancelShipmentResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<cancelShipmentResult>'.$result.'</cancelShipmentResult></cancelShipmentResponse>'
    );
}

function smsaCancelErrorXml(string $result = 'Failed - Already delivered'): string
{
    return smsaEnvelope(
        '<cancelShipmentResponse xmlns="http://track.smsaexpress.com/secom/">'
        .'<cancelShipmentResult>'.htmlspecialchars($result, ENT_XML1, 'UTF-8').'</cancelShipmentResult></cancelShipmentResponse>'
    );
}

function smsaDriverWithFake(array|callable $stub): array
{
    $http = new Factory;
    $http->fake($stub);

    return [$http, SmsaDriver::fromConnection($http, smsaConnection())];
}

test('smsa creates a shipment via raw soap addship', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaAddShipSuccessXml('290019315792'), 200, ['Content-Type' => 'text/xml']),
    ]);

    $result = $driver->createShipment(smsaDraft(), smsaConnection());

    expect($result->id)->toBe('290019315792')
        ->and($result->trackingNumber)->toBe('290019315792')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->carrier)->toBe('smsa')
        ->and($result->raw)->not->toHaveKey('passkey')
        ->and($result->raw)->not->toHaveKey('passKey');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<passKey>test-passkey</passKey>')
        ->and($body)->toContain('<cMobile>0555555555</cMobile>')
        ->and($body)->toContain('<cCity>JEDDAH</cCity>')
        ->and($body)->toContain('<codAmt>50</codAmt>')
        ->and($body)->toContain('<shipType>DLV</shipType>')
        ->and($body)->toContain('<refNo>ORD-42</refNo>');

    $contentType = implode(',', $request->header('Content-Type') ?? []);
    $soapAction = implode(',', $request->header('SOAPAction') ?? []);

    expect($contentType)->toContain('text/xml')
        ->and($soapAction)->toContain('addShip');
});

test('smsa create maps soap fault to carrier exception without credentials', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaFaultXml('Server was unable to process request.'), 200, ['Content-Type' => 'text/xml']),
    ]);

    try {
        $driver->createShipment(smsaDraft(), smsaConnection());
        expect(false)->toBeTrue('Expected ShippingException was not thrown.');
    } catch (ShippingException $e) {
        expect($e->getMessage())->toContain('SOAP Fault')
            ->and($e->getMessage())->not->toContain('test-passkey');
    }
});

test('smsa create maps addship error strings to carrier exception', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaAddShipErrorXml('Failed - Invalid passkey'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->createShipment(smsaDraft(), smsaConnection()))
        ->toThrow(ShippingException::class, 'addShip failed');
});

test('smsa requires consignee mobile', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaAddShipSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $draft = new ShipmentDraft(
        origin: new Address(name: 'Warehouse', line1: '1 King Rd', city: 'Riyadh', countryCode: 'SA'),
        destination: new Address(name: 'No Phone', line1: '10 Fahad Rd', city: 'Jeddah', countryCode: 'SA'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($draft, smsaConnection()))
        ->toThrow(ShippingException::class, 'consignee mobile');
});

test('smsa tracks with normalized events from gettrack', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaTrackSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = $driver->track('290019315792', smsaConnection());

    expect($tracked->trackingNumber)->toBe('290019315792')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events[0]->location)->toBe('Jeddah')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::PickedUp)
        ->and($tracked->raw)->not->toHaveKey('passkey');
});

test('smsa track falls back to getstatus when gettrack is empty', function () {
    $http = new Factory;
    $http->fake(function ($request) {
        $body = $request->body();

        if (str_contains($body, '<getTrack')) {
            return Factory::response(smsaTrackEmptyXml(), 200, ['Content-Type' => 'text/xml']);
        }

        return Factory::response(smsaStatusXml('Delivered'), 200, ['Content-Type' => 'text/xml']);
    });

    $driver = SmsaDriver::fromConnection($http, smsaConnection());
    $tracked = $driver->track('290019315792', smsaConnection());

    expect($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(1)
        ->and($tracked->events[0]->description)->toBe('Delivered');
});

test('smsa label returns pdf contents from getpdf', function () {
    $pdf = base64_encode('%PDF-1.4 label');

    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaPdfXml($pdf), 200, ['Content-Type' => 'text/xml']),
    ]);

    $label = $driver->label('290019315792', smsaConnection());

    expect($label->shipmentId)->toBe('290019315792')
        ->and($label->contents)->toBe($pdf)
        ->and($label->base64Encoded)->toBeTrue();
});

test('smsa label throws on empty pdf', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaPdfXml(''), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->label('290019315792', smsaConnection()))
        ->toThrow(ShippingException::class);
});

test('smsa cancels via cancelshipment', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaCancelSuccessXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect($driver->cancel('290019315792', smsaConnection()))->toBeTrue();
});

test('smsa cancel failure maps to carrier exception', function () {
    [$http, $driver] = smsaDriverWithFake([
        '*' => Factory::response(smsaCancelErrorXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => $driver->cancel('290019315792', smsaConnection()))
        ->toThrow(ShippingException::class);
});

test('smsa declares honest capabilities', function () {
    $driver = SmsaDriver::fromConnection(new Factory, smsaConnection());
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

test('smsa return travels via addship shiptype ret', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(smsaAddShipSuccessXml('290019315793'), 200, ['Content-Type' => 'text/xml']),
    ]);

    $driver = SmsaDriver::fromConnection($http, smsaConnection());
    $result = $driver->createShipment(smsaDraft(['ship_type' => 'RET']), smsaConnection());

    expect($result->trackingNumber)->toBe('290019315793');

    [$request] = $http->recorded()[0];

    expect($request->body())->toContain('<shipType>RET</shipType>');
});
