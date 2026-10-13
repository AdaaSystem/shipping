<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Drivers\Yurtici\YurticiDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function yurticiConnection(string $id = 'conn_yurtici_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'yurtici',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'ws_user_name' => 'YKTEST',
            'ws_password' => 'YK',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function yurticiDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR', phone: '02121234567'),
        destination: new Address(name: 'Mehmet Yılmaz', line1: 'Eski Büyükdere Cd 3', city: 'İstanbul', state: 'Maslak', countryCode: 'TR', phone: '05554443322'),
        parcels: [new Parcel(weightKg: 2.5, description: 'Elektronik')],
        reference: 'ORD-001',
        metadata: array_merge(['desi' => 3], $metadata),
    );
}

function yurticiEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function yurticiCreateXml(string $outFlag = '0', string $outResult = '', string $jobId = 'JOB-1', string $cargoKey = 'ORD-001'): string
{
    return yurticiEnvelope(
        '<createShipmentResponse xmlns="http://yurticikargo.com/">'
        .'<ShippingOrderResultVO><outFlag>'.$outFlag.'</outFlag>'
        .'<outResult>'.htmlspecialchars($outResult, ENT_XML1, 'UTF-8').'</outResult>'
        .'<jobId>'.$jobId.'</jobId>'
        .'<shippingOrderDetailVO><cargoKey>'.$cargoKey.'</cargoKey></shippingOrderDetailVO>'
        .'</ShippingOrderResultVO></createShipmentResponse>'
    );
}

function yurticiQueryXml(string $status = 'IND', string $code = '4', string $message = 'Kargo Teslimattadır'): string
{
    return yurticiEnvelope(
        '<queryShipmentResponse xmlns="http://yurticikargo.com/">'
        .'<ShippingDeliveryVO><outFlag>0</outFlag><outResult></outResult>'
        .'<shippingDeliveryDetailVO><docId>YK-DOC-9</docId>'
        .'<operationStatus>'.$status.'</operationStatus><operationCode>'.$code.'</operationCode>'
        .'<operationMessage>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</operationMessage>'
        .'<invDocCargoVOArray><operationStatus>'.$status.'</operationStatus><operationCode>'.$code.'</operationCode>'
        .'<operationMessage>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</operationMessage>'
        .'<operationDate>2026-01-02</operationDate><unitName>Maslak</unitName></invDocCargoVOArray>'
        .'</shippingDeliveryDetailVO></ShippingDeliveryVO></queryShipmentResponse>'
    );
}

function yurticiQueryErrorXml(string $outResult = 'Kayıt bulunamadı'): string
{
    return yurticiEnvelope(
        '<queryShipmentResponse xmlns="http://yurticikargo.com/">'
        .'<ShippingDeliveryVO><outFlag>1</outFlag>'
        .'<outResult>'.htmlspecialchars($outResult, ENT_XML1, 'UTF-8').'</outResult>'
        .'</ShippingDeliveryVO></queryShipmentResponse>'
    );
}

function yurticiCancelXml(string $status = 'CNL', string $message = 'Kargo çıkışı engellendi'): string
{
    return yurticiEnvelope(
        '<cancelShipmentResponse xmlns="http://yurticikargo.com/">'
        .'<return><operationStatus>'.$status.'</operationStatus><operationCode>3</operationCode>'
        .'<operationMessage>'.htmlspecialchars($message, ENT_XML1, 'UTF-8').'</operationMessage>'
        .'</return></cancelShipmentResponse>'
    );
}

function yurticiFaultXml(): string
{
    return yurticiEnvelope(
        '<soap:Fault><faultcode>soap:Server</faultcode>'
        .'<faultstring>Internal service error.</faultstring></soap:Fault>'
    );
}

test('yurtici creates a shipment via createshipment', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiCreateXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $driver = YurticiDriver::fromConnection($http, yurticiConnection());
    $result = $driver->createShipment(yurticiDraft(), yurticiConnection());

    expect($result->id)->toBe('JOB-1')
        ->and($result->trackingNumber)->toBe('ORD-001')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->raw)->not->toHaveKey('wsPassword')
        ->and($result->raw)->not->toHaveKey('wsUserName');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<createShipment xmlns="http://yurticikargo.com/">')
        ->and($body)->toContain('<wsUserName>YKTEST</wsUserName>')
        ->and($body)->toContain('<userLanguage>TR</userLanguage>')
        ->and($body)->toContain('<ShippingOrderVO>')
        ->and($body)->toContain('<cargoKey>ORD-001</cargoKey>')
        ->and($body)->toContain('<receiverCustName>Mehmet Yılmaz</receiverCustName>')
        ->and($body)->toContain('<cityName>İstanbul</cityName>')
        ->and($body)->toContain('<townName>Maslak</townName>');
});

test('yurtici create maps outflag errors to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiCreateXml('1', 'Geçersiz telefon'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => YurticiDriver::fromConnection($http, yurticiConnection())->createShipment(yurticiDraft(), yurticiConnection()))
        ->toThrow(ShippingException::class, 'Geçersiz telefon');
});

test('yurtici requires credentials and consignee phone', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(yurticiCreateXml(), 200, ['Content-Type' => 'text/xml'])]);

    $noCreds = YurticiDriver::fromConnection($http, yurticiConnection('conn_yk_2', ['ws_user_name' => '', 'ws_password' => '']));

    expect(fn () => $noCreds->createShipment(yurticiDraft(), yurticiConnection()))
        ->toThrow(ShippingException::class, 'ws_user_name');

    $driver = YurticiDriver::fromConnection($http, yurticiConnection());
    $noPhone = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Cd 3', city: 'İstanbul', state: 'Maslak', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noPhone, yurticiConnection()))
        ->toThrow(ShippingException::class, 'phone');
});

test('yurtici return folds the reason into description', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiCreateXml('0', '', 'JOB-R', 'RTN-ORD-1'), 200, ['Content-Type' => 'text/xml']),
    ]);

    $result = YurticiDriver::fromConnection($http, yurticiConnection())
        ->createShipment(yurticiDraft(['shipment_kind' => 'return', 'reason' => 'Hasarlı ürün']), yurticiConnection());

    expect($result->trackingNumber)->toBe('RTN-ORD-1');

    [$request] = $http->recorded()[0];
    expect($request->body())->toContain('Hasarlı ürün');
});

test('yurtici tracks with operation status and history', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiQueryXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = YurticiDriver::fromConnection($http, yurticiConnection())->track('ORD-001', yurticiConnection());

    expect($tracked->trackingNumber)->toBe('YK-DOC-9')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(1)
        ->and($tracked->events[0]->location)->toBe('Maslak')
        ->and($tracked->events[0]->occurredAt)->toBe('2026-01-02');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<queryShipment')
        ->and($body)->toContain('<wsLanguage>TR</wsLanguage>')
        ->and($body)->toContain('<keys>ORD-001</keys>')
        ->and($body)->toContain('<addHistoricalData>true</addHistoricalData>');
});

test('yurtici track maps missing records to not found', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiQueryErrorXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => YurticiDriver::fromConnection($http, yurticiConnection())->track('ORD-MISSING', yurticiConnection()))
        ->toThrow(ShippingException::class, 'ORD-MISSING');
});

test('yurtici track maps generic outflag errors to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiQueryErrorXml('Servis hatası'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => YurticiDriver::fromConnection($http, yurticiConnection())->track('ORD-001', yurticiConnection()))
        ->toThrow(ShippingException::class, 'Servis hatası');
});

test('yurtici cancels via cancelshipment', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiCancelXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(YurticiDriver::fromConnection($http, yurticiConnection())->cancel('ORD-001', yurticiConnection()))->toBeTrue();

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<cancelShipment')
        ->and($body)->toContain('<cargoKeys>ORD-001</cargoKeys>');
});

test('yurtici cancel refusal maps to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiCancelXml('NOP', 'Kargo işlem görmemiş'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => YurticiDriver::fromConnection($http, yurticiConnection())->cancel('ORD-001', yurticiConnection()))
        ->toThrow(ShippingException::class, 'cancel failed');
});

test('yurtici maps soap faults without credentials', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(yurticiFaultXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    try {
        YurticiDriver::fromConnection($http, yurticiConnection())->createShipment(yurticiDraft(), yurticiConnection());
        expect(false)->toBeTrue('Expected ShippingException was not thrown.');
    } catch (ShippingException $e) {
        expect($e->getMessage())->toContain('SOAP Fault')
            ->and($e->getMessage())->not->toContain('YKTEST');
    }
});

test('yurtici declares honest capabilities without labels', function () {
    $driver = YurticiDriver::fromConnection(new Factory, yurticiConnection());
    $caps = $driver->capabilities();

    expect($caps->create)->toBeTrue()
        ->and($caps->track)->toBeTrue()
        ->and($caps->cancel)->toBeTrue()
        ->and($caps->return)->toBeTrue()
        ->and($caps->exchange)->toBeTrue()
        ->and($caps->cod)->toBeTrue()
        ->and($caps->label)->toBeFalse()
        ->and($caps->rates)->toBeFalse()
        ->and($driver)->not->toBeInstanceOf(SupportsLabels::class);
});
