<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Drivers\Ptt\PttDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function pttConnection(string $id = 'conn_ptt_1', array $credentials = [], array $metadata = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'ptt',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'musteri_id' => '999999',
            'sifre' => 'ptt-pass',
            'kullanici' => 'PttWs',
        ], $credentials),
        merchantId: 'merchant_7',
        metadata: $metadata,
    );
}

function pttDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo Lojistik', line1: 'Sanayi Cd 1', city: 'İstanbul', state: 'Pendik', countryCode: 'TR', phone: '02120000000'),
        destination: new Address(name: 'Ali Yılmaz', line1: 'Atatürk Mah. No:5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Kitap')],
        reference: 'ORD-42',
        metadata: array_merge(['barcode' => 'PTT1234567890', 'cod' => 150], $metadata),
    );
}

function pttEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function pttUploadXml(int $hataKodu = 1, string $aciklama = 'Başarılı', string $barkod = 'PTT1234567890'): string
{
    return pttEnvelope(
        '<ns:kabulEkle2Response xmlns:ns="http://kabul.ptt.gov.tr">'
        .'<ns:return><ns:hataKodu>'.$hataKodu.'</ns:hataKodu>'
        .'<ns:aciklama>'.htmlspecialchars($aciklama, ENT_XML1, 'UTF-8').'</ns:aciklama>'
        .'<ns:dongu><ns:barkod>'.$barkod.'</ns:barkod><ns:donguAciklama>OK</ns:donguAciklama>'
        .'<ns:donguHataKodu>1</ns:donguHataKodu><ns:donguSonuc>true</ns:donguSonuc></ns:dongu>'
        .'</ns:return></ns:kabulEkle2Response>'
    );
}

function pttTrackXml(string $sonIslem = 'Teslim Edildi'): string
{
    return pttEnvelope(
        '<ns:barkodSorguResponse xmlns:ns="http://hareket.ptt.gov.tr">'
        .'<ns:return><ns:hataKodu>0</ns:hataKodu><ns:barkod>PTT1234567890</ns:barkod>'
        .'<ns:sonIslemAciklama>'.htmlspecialchars($sonIslem, ENT_XML1, 'UTF-8').'</ns:sonIslemAciklama>'
        .'<ns:sonIslemTarih>2026-01-03</ns:sonIslemTarih><ns:sonIslemIsyeri>Ankara PİM</ns:sonIslemIsyeri>'
        .'<ns:kabulTarih>2026-01-01</ns:kabulTarih><ns:kabulIsyeri>İstanbul PİM</ns:kabulIsyeri>'
        .'</ns:return></ns:barkodSorguResponse>'
    );
}

function pttTrackMissingXml(): string
{
    return pttEnvelope(
        '<ns:barkodSorguResponse xmlns:ns="http://hareket.ptt.gov.tr">'
        .'<ns:return><ns:hataKodu>2</ns:hataKodu><ns:aciklama>Kayıt bulunamadı</ns:aciklama>'
        .'</ns:return></ns:barkodSorguResponse>'
    );
}

function pttDeleteXml(int $hataKodu = 1, string $aciklama = 'Silindi'): string
{
    return pttEnvelope(
        '<ns:barkodVeriSilResponse xmlns:ns="http://kabul.ptt.gov.tr">'
        .'<ns:return><ns:hataKodu>'.$hataKodu.'</ns:hataKodu>'
        .'<ns:aciklama>'.htmlspecialchars($aciklama, ENT_XML1, 'UTF-8').'</ns:aciklama>'
        .'</ns:return></ns:barkodVeriSilResponse>'
    );
}

function pttExceptionXml(): string
{
    return pttEnvelope(
        '<ns:SorguException xmlns:ns="http://kabul.ptt.gov.tr"><ns:Message>Hatalı şifre</ns:Message></ns:SorguException>'
    );
}

test('ptt creates a shipment via wsdl-verified kabulekle2', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttUploadXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $driver = PttDriver::fromConnection($http, pttConnection());
    $result = $driver->createShipment(pttDraft(), pttConnection());

    expect($result->id)->toBe('PTT1234567890')
        ->and($result->trackingNumber)->toBe('PTT1234567890')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->raw['dosya_adi'])->not->toBe('')
        ->and($result->raw)->not->toHaveKey('sifre')
        ->and($result->raw)->not->toHaveKey('musteri_id');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<ns:kabulEkle2>')
        ->and($body)->toContain('<ns:dongu>')
        ->and($body)->toContain('<ns:barkodNo>PTT1234567890</ns:barkodNo>')
        ->and($body)->toContain('<ns:aliciAdi>Ali Yılmaz</ns:aliciAdi>')
        ->and($body)->toContain('<ns:aliciIlAdi>Ankara</ns:aliciIlAdi>')
        ->and($body)->toContain('<ns:aliciIlceAdi>Çankaya</ns:aliciIlceAdi>')
        ->and($body)->toContain('<ns:odemesekli>UA</ns:odemesekli>')
        ->and($body)->toContain('<ns:gondericibilgi>')
        ->and($body)->not->toContain('kabulEkle2Input');

    $soapAction = implode(',', $request->header('SOAPAction') ?? []);
    expect($soapAction)->toBe('urn:kabulEkle2');
});

test('ptt create maps hatakodu errors to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttUploadXml(0, 'Barkod havuzda yok', ''), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => PttDriver::fromConnection($http, pttConnection())->createShipment(pttDraft(), pttConnection()))
        ->toThrow(ShippingException::class, 'Barkod havuzda yok');
});

test('ptt requires a pre-allocated barcode and receiver sms', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(pttUploadXml(), 200, ['Content-Type' => 'text/xml'])]);
    $driver = PttDriver::fromConnection($http, pttConnection());

    expect(fn () => $driver->createShipment(pttDraft(['barcode' => '']), pttConnection()))
        ->toThrow(ShippingException::class, 'pre-allocated barcode');

    $noSms = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Mah 5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
        metadata: ['barcode' => 'PTT0001'],
    );

    expect(fn () => $driver->createShipment($noSms, pttConnection()))
        ->toThrow(ShippingException::class, 'SMS');
});

test('ptt tracks via barkodsorgu milestones', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttTrackXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = PttDriver::fromConnection($http, pttConnection())->track('PTT1234567890', pttConnection());

    expect($tracked->trackingNumber)->toBe('PTT1234567890')
        ->and($tracked->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->location)->toBe('İstanbul PİM')
        ->and($tracked->events[1]->location)->toBe('Ankara PİM');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<ns:barkodSorgu>')
        ->and($body)->toContain('<ns:barkod>PTT1234567890</ns:barkod>')
        ->and($body)->toContain('<ns:musteri_no>999999</ns:musteri_no>');

    $soapAction = implode(',', $request->header('SOAPAction') ?? []);
    expect($soapAction)->toBe('urn:barkodSorgu');
});

test('ptt track maps missing barcodes to not found', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttTrackMissingXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => PttDriver::fromConnection($http, pttConnection())->track('PTT-MISSING', pttConnection()))
        ->toThrow(ShippingException::class, 'PTT-MISSING');
});

test('ptt cancels via barkodverisil with dosyaadi', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttDeleteXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $connection = pttConnection('conn_ptt_2', [], ['dosya_adi' => '20260101-120000-abc']);

    expect(PttDriver::fromConnection($http, $connection)->cancel('PTT1234567890', $connection))->toBeTrue();

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<ns:barcode>PTT1234567890</ns:barcode>')
        ->and($body)->toContain('<ns:dosyaAdi>20260101-120000-abc</ns:dosyaAdi>');

    $soapAction = implode(',', $request->header('SOAPAction') ?? []);
    expect($soapAction)->toBe('urn:barkodVeriSil');
});

test('ptt cancel requires the create-time dosyaadi', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(pttDeleteXml(), 200, ['Content-Type' => 'text/xml'])]);

    expect(fn () => PttDriver::fromConnection($http, pttConnection())->cancel('PTT1234567890', pttConnection()))
        ->toThrow(ShippingException::class, 'dosyaAdi');
});

test('ptt cancel failure maps to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttDeleteXml(0, 'Kargo dağıtımda'), 200, ['Content-Type' => 'text/xml']),
    ]);

    $connection = pttConnection('conn_ptt_3', [], ['dosya_adi' => 'f1']);

    expect(fn () => PttDriver::fromConnection($http, $connection)->cancel('PTT1234567890', $connection))
        ->toThrow(ShippingException::class, 'dağıtımda');
});

test('ptt maps inline sorgu faults without credentials', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttExceptionXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    try {
        PttDriver::fromConnection($http, pttConnection())->createShipment(pttDraft(), pttConnection());
        expect(false)->toBeTrue('Expected ShippingException was not thrown.');
    } catch (ShippingException $e) {
        expect($e->getMessage())->toContain('Hatalı şifre')
            ->and($e->getMessage())->not->toContain('ptt-pass');
    }
});

test('ptt return travels via the same kabulekle2 call', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(pttUploadXml(1, 'Başarılı', 'PTT-RTN-1'), 200, ['Content-Type' => 'text/xml']),
    ]);

    $result = PttDriver::fromConnection($http, pttConnection())
        ->createShipment(pttDraft(['shipment_kind' => 'return', 'barcode' => 'PTT-RTN-1']), pttConnection());

    expect($result->trackingNumber)->toBe('PTT-RTN-1')
        ->and($result->status)->toBe(ShipmentStatus::Returned);
});

test('ptt declares honest capabilities without labels', function () {
    $driver = PttDriver::fromConnection(new Factory, pttConnection());
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
