<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\Aras\ArasDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function arasConnection(string $id = 'conn_aras_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'aras',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'username' => 'aras-user',
            'password' => 'aras-pass',
            'customer_code' => 'CUST-1',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function arasDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR', phone: '05551111111'),
        destination: new Address(name: 'Ali Yılmaz', line1: 'Atatürk Mah. No:5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Ayakkabı')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 150], $metadata),
    );
}

function arasEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function arasSetOrderXml(string $message = '', string $invoiceKey = 'INV-1'): string
{
    return arasEnvelope(
        '<SetOrderResponse xmlns="http://tempuri.org/">'
        .'<SetOrderResult><OrderResultInfo><ResultCode>0</ResultCode>'
        .'<ResultMessage>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</ResultMessage>'
        .'<InvoiceKey>'.$invoiceKey.'</InvoiceKey></OrderResultInfo></SetOrderResult></SetOrderResponse>'
    );
}

function arasQueryJsonXml(): string
{
    $json = htmlspecialchars(json_encode([
        'HAREKETLER' => [
            ['DURUM' => 'TRANSFER MERKEZİNDE', 'TARIH' => '2026-01-01', 'SUBE' => 'İstanbul'],
            ['DURUM' => 'DAĞITIMDA', 'TARIH' => '2026-01-02', 'SUBE' => 'Ankara'],
        ],
    ]) ?: '', ENT_XML1, 'UTF-8');

    return arasEnvelope(
        '<GetQueryJSONResponse xmlns="http://tempuri.org/">'
        .'<GetQueryJSONResult>'.$json.'</GetQueryJSONResult></GetQueryJSONResponse>'
    );
}

function arasQueryJsonEmptyXml(): string
{
    return arasEnvelope(
        '<GetQueryJSONResponse xmlns="http://tempuri.org/">'
        .'<GetQueryJSONResult>[]</GetQueryJSONResult></GetQueryJSONResponse>'
    );
}

function arasCargoInfoXml(string $durum = 'TESLİM EDİLDİ'): string
{
    return arasEnvelope(
        '<GetCargoInfoResponse xmlns="http://tempuri.org/">'
        .'<GetCargoInfoResult><IrsData><Table><DURUM>'.htmlspecialchars($durum, ENT_XML1, 'UTF-8').'</DURUM>'
        .'<TARIH>2026-01-03</TARIH><SUBE>Ankara</SUBE></Table></IrsData></GetCargoInfoResult></GetCargoInfoResponse>'
    );
}

function arasCancelXml(string $message = 'OK'): string
{
    return arasEnvelope(
        '<CancelDispatchResponse xmlns="http://tempuri.org/">'
        .'<CancelDispatchResult><DispatchResultInfo><ResultCode>0</ResultCode>'
        .'<ResultMessage>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</ResultMessage>'
        .'</DispatchResultInfo></CancelDispatchResult></CancelDispatchResponse>'
    );
}

function arasBarcodeXml(string $contents): string
{
    return arasEnvelope(
        '<GetArasBarcodeResponse xmlns="http://tempuri.org/">'
        .'<GetArasBarcodeResult><ZebraPdf><string>'.htmlspecialchars($contents, ENT_XML1, 'UTF-8').'</string></ZebraPdf>'
        .'<ResultCode>0</ResultCode></GetArasBarcodeResult></GetArasBarcodeResponse>'
    );
}

function arasFaultXml(): string
{
    return arasEnvelope(
        '<soap:Fault><faultcode>soap:Server</faultcode>'
        .'<faultstring>Server was unable to process request.</faultstring></soap:Fault>'
    );
}

test('aras creates a shipment via wsdl-verified setorder', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasSetOrderXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $driver = ArasDriver::fromConnection($http, arasConnection());
    $result = $driver->createShipment(arasDraft(), arasConnection());

    expect($result->trackingNumber)->toStartWith('SBORD42')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->raw)->not->toHaveKey('password');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<SetOrder xmlns="http://tempuri.org/">')
        ->and($body)->toContain('<orderInfo><Order>')
        ->and($body)->toContain('<ReceiverName>Ali Yılmaz</ReceiverName>')
        ->and($body)->toContain('<ReceiverCityName>Ankara</ReceiverCityName>')
        ->and($body)->toContain('<ReceiverTownName>Çankaya</ReceiverTownName>')
        ->and($body)->toContain('<CodAmount>150</CodAmount>')
        ->and($body)->toContain('<userName>aras-user</userName>')
        ->and($body)->not->toContain('<IsCod>')
        ->and($body)->not->toContain('SenderAccountAddressId');

    $soapAction = implode(',', $request->header('SOAPAction') ?? []);
    expect($soapAction)->toBe('http://tempuri.org/SetOrder');
});

test('aras create maps error resultmessage to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasSetOrderXml('Hata: geçersiz şehir'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => ArasDriver::fromConnection($http, arasConnection())->createShipment(arasDraft(), arasConnection()))
        ->toThrow(ShippingException::class, 'SetOrder failed');
});

test('aras requires receiver phone and town', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(arasSetOrderXml(), 200, ['Content-Type' => 'text/xml'])]);
    $driver = ArasDriver::fromConnection($http, arasConnection());

    $noPhone = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Mah 5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noPhone, arasConnection()))
        ->toThrow(ShippingException::class, 'phone');

    $noTown = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Mah 5', city: 'Ankara', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noTown, arasConnection()))
        ->toThrow(ShippingException::class, 'ReceiverTownName');
});

test('aras tracks via getqueryjson movements', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasQueryJsonXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = ArasDriver::fromConnection($http, arasConnection())->track('SBORD42', arasConnection());

    expect($tracked->trackingNumber)->toBe('SBORD42')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->location)->toBe('Ankara')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::InTransit);
});

test('aras track falls back to getcargoinfo', function () {
    $http = new Factory;
    $http->fake(function ($request) {
        $body = $request->body();

        if (str_contains($body, '<GetQueryJSON')) {
            return Factory::response(arasQueryJsonEmptyXml(), 200, ['Content-Type' => 'text/xml']);
        }

        return Factory::response(arasCargoInfoXml(), 200, ['Content-Type' => 'text/xml']);
    });

    $tracked = ArasDriver::fromConnection($http, arasConnection())->track('SBORD42', arasConnection());

    expect($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(1);

    [$query] = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->body(), '<GetQueryJSON')));
    expect($query[0]->body())->toContain('<CustomerCode>CUST-1</CustomerCode>');

    $cargo = array_values(array_filter($http->recorded()->all(), fn ($r) => str_contains($r[0]->body(), '<GetCargoInfo')));
    expect($cargo[0][0]->body())->toContain('<username>aras-user</username>');
});

test('aras track maps double-empty to not found', function () {
    $http = new Factory;
    $http->fake(function ($request) {
        if (str_contains($request->body(), '<GetQueryJSON')) {
            return Factory::response(arasQueryJsonEmptyXml(), 200, ['Content-Type' => 'text/xml']);
        }

        return Factory::response(arasCargoInfoXml(''), 200, ['Content-Type' => 'text/xml']);
    });

    expect(fn () => ArasDriver::fromConnection($http, arasConnection())->track('SB-MISSING', arasConnection()))
        ->toThrow(ShippingException::class, 'SB-MISSING');
});

test('aras label returns getarasbarcode pdf', function () {
    $pdf = base64_encode('%PDF-1.4 aras');

    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasBarcodeXml($pdf), 200, ['Content-Type' => 'text/xml']),
    ]);

    $label = ArasDriver::fromConnection($http, arasConnection())->label('SBORD42', arasConnection());

    expect($label->shipmentId)->toBe('SBORD42')
        ->and($label->contents)->toBe($pdf)
        ->and($label->base64Encoded)->toBeTrue();

    [$request] = $http->recorded()[0];
    expect($request->body())->toContain('<Username>aras-user</Username>');
});

test('aras label throws on empty barcode payload', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasEnvelope('<GetArasBarcodeResponse xmlns="http://tempuri.org/"><GetArasBarcodeResult><ResultCode>1</ResultCode><Message>Not ready</Message></GetArasBarcodeResult></GetArasBarcodeResponse>'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => ArasDriver::fromConnection($http, arasConnection())->label('SBORD42', arasConnection()))
        ->toThrow(ShippingException::class, 'Not ready');
});

test('aras cancels via canceldispatch', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasCancelXml('İptal edildi'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(ArasDriver::fromConnection($http, arasConnection())->cancel('SBORD42', arasConnection()))->toBeTrue();

    [$request] = $http->recorded()[0];
    expect($request->body())->toContain('<integrationCode>SBORD42</integrationCode>');
});

test('aras cancel failure maps to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasCancelXml('Hata: kargo dağıtımda'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => ArasDriver::fromConnection($http, arasConnection())->cancel('SBORD42', arasConnection()))
        ->toThrow(ShippingException::class, 'CancelDispatch failed');
});

test('aras maps soap faults without credentials', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(arasFaultXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    try {
        ArasDriver::fromConnection($http, arasConnection())->createShipment(arasDraft(), arasConnection());
        expect(false)->toBeTrue('Expected ShippingException was not thrown.');
    } catch (ShippingException $e) {
        expect($e->getMessage())->toContain('SOAP Fault')
            ->and($e->getMessage())->not->toContain('aras-pass');
    }
});

test('aras declares honest capabilities', function () {
    $caps = ArasDriver::fromConnection(new Factory, arasConnection())->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->label)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->rates)->toBeFalse()
        ->and($caps->webhooks)->toBeFalse();
});
