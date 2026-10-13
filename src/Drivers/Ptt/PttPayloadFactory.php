<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ptt;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → PTT `kabulEkle2` SOAP `input` fields.
 *
 * Field names follow the live upload WSDL `Input2` / `InputDongu2` types —
 * NOT the reference `mohamedhekal/shipbridge-ptt` payload verbatim. WSDL
 * corrections: `input` directly contains repeated `dongu` rows (no
 * `kabulEkle2Input` wrapper); there is NO `dosyaAdi` inside a row (it
 * lives on `input`); the sender block `gondericibilgi` is populated from
 * the draft origin (the reference ignores the origin entirely). Returns
 * and exchanges travel through the same `kabulEkle2` call with
 * `metadata.shipment_kind` (`forward` default, `return`, `exchange`).
 *
 * Extra PTT fields travel via ShipmentDraft::$metadata: barcode (required,
 * pre-allocated from the merchant pool), barkod_no, alici_il, alici_ilce,
 * district, phone, alici_tel, alici_email, desi, agirlik, musteri_referans_no,
 * odemesekli (`MH` sender-pays default, `UA` for COD), cod /
 * odeme_sart_ucreti, ekhizmet (`OS` for COD), dosya_adi, gonderi_tur,
 * gonderi_tip.
 */
final class PttPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return array{input: array<string, mixed>, dosya_adi: string, barcode: string, shipment_kind: string}
     */
    public function create(ShipmentDraft $draft): array
    {
        $metadata = $draft->metadata;
        $kind = strtolower((string) ($metadata['shipment_kind'] ?? 'forward'));

        if (! in_array($kind, ['forward', 'return', 'exchange'], true)) {
            $kind = 'forward';
        }

        $reference = ($kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '')).($draft->reference ?? '');

        if ($reference !== '') {
            $metadata['musteri_referans_no'] = $metadata['musteri_referans_no'] ?? $reference;
        }

        $destination = $kind === 'return' ? $draft->origin : $draft->destination;

        $item = $this->item($destination, $draft->origin, $draft->parcels, $reference !== '' ? $reference : null, $metadata);
        $dosyaAdi = (string) ($metadata['dosya_adi'] ?? date('Ymd-His-').uniqid());

        return [
            'input' => [
                'dongu' => [$item],
                'dosyaAdi' => $dosyaAdi,
                'gonderiTip' => (string) ($metadata['gonderi_tip'] ?? $this->config['gonderi_tip'] ?? 'NORMAL'),
                'gonderiTur' => (string) ($metadata['gonderi_tur'] ?? $this->config['gonderi_tur'] ?? 'KARGO'),
                'kullanici' => (string) ($this->config['kullanici'] ?? 'PttWs'),
                'musteriId' => (string) ($this->config['musteri_id'] ?? ''),
                'sifre' => (string) ($this->config['sifre'] ?? ''),
            ],
            'dosya_adi' => $dosyaAdi,
            'barcode' => $item['barkodNo'],
            'shipment_kind' => $kind,
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function item(
        Address $destination,
        Address $origin,
        array $parcels,
        ?string $reference,
        array $metadata,
    ): array {
        $barcode = (string) ($metadata['barcode'] ?? $metadata['barkod_no'] ?? '');

        if ($barcode === '') {
            throw ShippingException::carrierFailed('PTT requires a pre-allocated barcode in metadata.barcode.');
        }

        $sms = $destination->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($sms === null || $sms === '') {
            throw ShippingException::carrierFailed('PTT requires receiver SMS (Address::$phone).');
        }

        $city = (string) ($metadata['alici_il'] ?? $destination->city);
        $district = (string) ($metadata['alici_ilce'] ?? $metadata['district'] ?? $destination->state ?? '');

        if ($city === '' || $district === '') {
            throw ShippingException::carrierFailed('PTT requires aliciIlAdi and aliciIlceAdi.');
        }

        $weight = 0.0;

        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
        }

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $desi = (float) ($metadata['desi'] ?? max($weight, 1.0));
        $cod = (float) ($metadata['cod'] ?? $metadata['odeme_sart_ucreti'] ?? 0);
        $paymentType = (string) ($metadata['odemesekli'] ?? ($cod > 0 ? 'UA' : 'MH'));
        $extraService = (string) ($metadata['ekhizmet'] ?? ($cod > 0 ? 'OS' : ''));

        return [
            'aAdres' => trim($destination->line1.($destination->line2 !== null && $destination->line2 !== '' ? ' '.$destination->line2 : '')),
            'agirlik' => (int) round($weight),
            'aliciAdi' => $destination->name,
            'aliciIlAdi' => $city,
            'aliciIlceAdi' => $district,
            'aliciSms' => $this->normalizePhone($sms),
            'aliciTel' => $this->normalizePhone((string) ($metadata['alici_tel'] ?? $sms)),
            'aliciEmail' => (string) ($metadata['alici_email'] ?? $destination->email ?? ''),
            'barkodNo' => $barcode,
            'desi' => $desi,
            'musteriReferansNo' => (string) ($metadata['musteri_referans_no'] ?? $reference ?? $barcode),
            'odemesekli' => $paymentType,
            'ekhizmet' => $extraService,
            'odeme_sart_ucreti' => $cod > 0 ? $cod : 0,
            'gondericibilgi' => $this->sender($origin),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sender(Address $origin): array
    {
        [$first, $last] = $this->splitName($origin->name);

        return [
            'gonderici_adi' => $first,
            'gonderici_soyadi' => $last,
            'gonderici_adresi' => trim($origin->line1.($origin->line2 !== null && $origin->line2 !== '' ? ' '.$origin->line2 : '')),
            'gonderici_il_ad' => $origin->city,
            'gonderici_ilce_ad' => (string) ($origin->state ?? ''),
            'gonderici_posta_kodu' => (string) ($origin->postalCode ?? ''),
            'gonderici_sms' => $this->normalizePhone((string) ($origin->phone ?? '')),
            'gonderici_telefonu' => $this->normalizePhone((string) ($origin->phone ?? '')),
            'gonderici_email' => (string) ($origin->email ?? ''),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        if ($parts === false || $parts === [] || trim($parts[0] ?? '') === '') {
            return ['Alici', ''];
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
    }
}
