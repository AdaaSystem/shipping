<?php

declare(strict_types=1);

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Drivers\Surat\SuratDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory;

function suratConnection(string $id = 'conn_surat_1', array $credentials = []): ShippingConnectionContext
{
    return new ShippingConnectionContext(
        connectionId: $id,
        carrierCode: 'surat',
        mode: ConnectionMode::Merchant,
        environment: Environment::Production,
        credentials: array_merge([
            'kullanici_adi' => 'cari-123',
            'sifre' => 'sifre-pass',
            'web_sifre' => 'web-pass',
        ], $credentials),
        merchantId: 'merchant_7',
    );
}

function suratDraft(array $metadata = []): ShipmentDraft
{
    return new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR', phone: '05551111111'),
        destination: new Address(name: 'Ali Yılmaz', line1: 'Atatürk Mah. No:5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 2.0, description: 'Ayakkabı')],
        reference: 'ORD-42',
        metadata: array_merge(['cod' => 150], $metadata),
    );
}

function suratEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
}

function suratCreateXml(string $sonuc = 'Tamam', string $takipNo = '12345678901234', string $ozel = 'ORD-42'): string
{
    return suratEnvelope(
        '<GonderiyiKargoyaGonderYeniSiparisBarkodOlusturResponse xmlns="http://tempuri.org/">'
        .'<GonderiyiKargoyaGonderYeniSiparisBarkodOlusturResult>'
        .'<Sonuc>'.htmlspecialchars($sonuc, ENT_XML1, 'UTF-8').'</Sonuc>'
        .'<TakipNo>'.$takipNo.'</TakipNo>'
        .'<OzelKargoTakipNo>'.$ozel.'</OzelKargoTakipNo>'
        .'</GonderiyiKargoyaGonderYeniSiparisBarkodOlusturResult>'
        .'</GonderiyiKargoyaGonderYeniSiparisBarkodOlusturResponse>'
    );
}

function suratTrackXml(): string
{
    return suratEnvelope(
        '<KargoTakipHareketDetayliV2Response xmlns="http://tempuri.org/">'
        .'<KargoTakipHareketDetayliV2Result><Hareketler>'
        .'<Hareket><Durum>ŞUBE KABUL</Durum><Aciklama>Şube kabul etti</Aciklama><Tarih>2026-01-01</Tarih><Sube>İstanbul</Sube></Hareket>'
        .'<Hareket><Durum>DAĞITIMDA</Durum><Aciklama>Dağıtıma çıktı</Aciklama><Tarih>2026-01-02</Tarih><Sube>Ankara</Sube></Hareket>'
        .'</Hareketler></KargoTakipHareketDetayliV2Result></KargoTakipHareketDetayliV2Response>'
    );
}

function suratTrackEmptyXml(): string
{
    return suratEnvelope(
        '<KargoTakipHareketDetayliV2Response xmlns="http://tempuri.org/">'
        .'<KargoTakipHareketDetayliV2Result><Hareketler /></KargoTakipHareketDetayliV2Result></KargoTakipHareketDetayliV2Response>'
    );
}

function suratCancelXml(string $result = 'Tamam'): string
{
    return suratEnvelope(
        '<GonderiGeriCekResponse xmlns="http://tempuri.org/">'
        .'<GonderiGeriCekResult>'.htmlspecialchars($result, ENT_XML1, 'UTF-8').'</GonderiGeriCekResult></GonderiGeriCekResponse>'
    );
}

function suratFaultXml(): string
{
    return suratEnvelope(
        '<soap:Fault><faultcode>soap:Server</faultcode>'
        .'<faultstring>Object reference not set.</faultstring></soap:Fault>'
    );
}

test('surat creates a shipment via barkod olustur', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratCreateXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $driver = SuratDriver::fromConnection($http, suratConnection());
    $result = $driver->createShipment(suratDraft(), suratConnection());

    expect($result->id)->toBe('ORD-42')
        ->and($result->trackingNumber)->toBe('ORD-42')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->raw['barcode'])->toBe('12345678901234')
        ->and($result->raw)->not->toHaveKey('Sifre')
        ->and($result->raw)->not->toHaveKey('sifre');

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<KullaniciAdi>cari-123</KullaniciAdi>')
        ->and($body)->toContain('<Sifre>sifre-pass</Sifre>')
        ->and($body)->toContain('<KisiKurum>Ali Yılmaz</KisiKurum>')
        ->and($body)->toContain('<Il>Ankara</Il>')
        ->and($body)->toContain('<Ilce>Çankaya</Ilce>')
        ->and($body)->toContain('<OzelKargoTakipNo>ORD-42</OzelKargoTakipNo>')
        ->and($body)->toContain('<KapidanOdemeTutar>150</KapidanOdemeTutar>')
        ->and($body)->toContain('<KapidanOdeme>1</KapidanOdeme>');

    $soapAction = implode(',', $request->header('SOAPAction') ?? []);
    expect($soapAction)->toContain('GonderiyiKargoyaGonderYeniSiparisBarkodOlustur');
});

test('surat create falls back to takipno without ozel takip', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratCreateXml('Tamam', '12345678901234', ''), 200, ['Content-Type' => 'text/xml']),
    ]);

    $draft = suratDraft(['ozel_kargo_takip_no' => '']);
    $result = SuratDriver::fromConnection($http, suratConnection())->createShipment($draft, suratConnection());

    expect($result->trackingNumber)->toBe('12345678901234');
});

test('surat create maps sonuc errors to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratCreateXml('Hata: geçersiz il'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => SuratDriver::fromConnection($http, suratConnection())->createShipment(suratDraft(), suratConnection()))
        ->toThrow(ShippingException::class, 'create failed');
});

test('surat requires il ilce and mobile', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(suratCreateXml(), 200, ['Content-Type' => 'text/xml'])]);
    $driver = SuratDriver::fromConnection($http, suratConnection());

    $noCity = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Mah 5', city: '', state: '', countryCode: 'TR', phone: '05551234567'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noCity, suratConnection()))
        ->toThrow(ShippingException::class, 'Ilce');

    $noPhone = new ShipmentDraft(
        origin: new Address(name: 'Depo', line1: 'Sanayi Cd 1', city: 'İstanbul', countryCode: 'TR'),
        destination: new Address(name: 'Ali', line1: 'Mah 5', city: 'Ankara', state: 'Çankaya', countryCode: 'TR'),
        parcels: [new Parcel(weightKg: 1.0)],
        reference: 'ORD-9',
    );

    expect(fn () => $driver->createShipment($noPhone, suratConnection()))
        ->toThrow(ShippingException::class, 'mobile');
});

test('surat tracks with reversed hareket events', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratTrackXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    $tracked = SuratDriver::fromConnection($http, suratConnection())->track('ORD-42', suratConnection());

    expect($tracked->trackingNumber)->toBe('ORD-42')
        ->and($tracked->status)->toBe(ShipmentStatus::OutForDelivery)
        ->and($tracked->events)->toHaveCount(2)
        ->and($tracked->events[0]->location)->toBe('Ankara')
        ->and($tracked->events[0]->description)->toBe('Dağıtıma çıktı')
        ->and($tracked->events[1]->status)->toBe(ShipmentStatus::PickedUp);

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<CariKodu>cari-123</CariKodu>')
        ->and($body)->toContain('<WebSiparisKodu>ORD-42</WebSiparisKodu>');
});

test('surat track maps empty movements to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratTrackEmptyXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => SuratDriver::fromConnection($http, suratConnection())->track('ORD-42', suratConnection()))
        ->toThrow(ShippingException::class, 'no events');
});

test('surat cancels via gonderigericek', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratCancelXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(SuratDriver::fromConnection($http, suratConnection())->cancel('ORD-42', suratConnection()))->toBeTrue();

    [$request] = $http->recorded()[0];
    $body = $request->body();

    expect($body)->toContain('<WebSiparisKodu>ORD-42</WebSiparisKodu>')
        ->and($body)->toContain('<IptalNeden>Cancelled by merchant</IptalNeden>');
});

test('surat cancel failure maps to carrier exception', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratCancelXml('Hata: kargo teslim edilmiş'), 200, ['Content-Type' => 'text/xml']),
    ]);

    expect(fn () => SuratDriver::fromConnection($http, suratConnection())->cancel('ORD-42', suratConnection()))
        ->toThrow(ShippingException::class, 'cancel failed');
});

test('surat maps soap faults without credentials', function () {
    $http = new Factory;
    $http->fake([
        '*' => Factory::response(suratFaultXml(), 200, ['Content-Type' => 'text/xml']),
    ]);

    try {
        SuratDriver::fromConnection($http, suratConnection())->createShipment(suratDraft(), suratConnection());
        expect(false)->toBeTrue('Expected ShippingException was not thrown.');
    } catch (ShippingException $e) {
        expect($e->getMessage())->toContain('SOAP Fault')
            ->and($e->getMessage())->not->toContain('sifre-pass');
    }
});

test('surat declares honest capabilities without labels', function () {
    $driver = SuratDriver::fromConnection(new Factory, suratConnection());
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
