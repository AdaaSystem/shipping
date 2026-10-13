<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Surat;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Sürat Kargo SOAP shipment fields.
 *
 * Field map follows the reference `mohamedhekal/shipbridge-surat`
 * `Support/PayloadFactory` source (`KullaniciAdi` / `Sifre` / `WebSifre`
 * auth in the create body; `KisiKurum`, `AliciAdresi`, `Il`, `Ilce`,
 * `TelefonCep`, `KargoTuru`, `OdemeTipi`, `Adet`, `OzelKargoTakipNo`,
 * `BirimDesi`, `BirimKg`, `KapidanOdemeTutar` / `KapidanOdeme`,
 * `Gonderen`, `Aciklama`). Returns and exchanges travel through the same
 * create operation with `metadata.shipment_kind` (`forward` default,
 * `return`, `exchange`) and `metadata.reason` as `Aciklama`.
 *
 * Extra Sürat fields travel via ShipmentDraft::$metadata: il, ilce,
 * district, phone, kargo_turu, odeme_tipi, adet, ozel_kargo_takip_no,
 * desi, birim_desi, cod, kapidan_odeme_tutar, gonderen, aciklama.
 */
final class SuratPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(ShipmentDraft $draft): array
    {
        $metadata = $draft->metadata;
        $kind = strtolower((string) ($metadata['shipment_kind'] ?? 'forward'));

        if (! in_array($kind, ['forward', 'return', 'exchange'], true)) {
            $kind = 'forward';
        }

        $reference = ($kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '')).($draft->reference ?? ('SB'.time()));

        if ($kind !== 'forward' && isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['aciklama'] = $metadata['reason'];
        }

        return $this->shipment(
            destination: $kind === 'return' ? $draft->origin : $draft->destination,
            parcels: $draft->parcels,
            reference: $reference,
            metadata: $metadata,
            shipperName: $kind === 'forward' ? null : $draft->origin->name,
        );
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function shipment(
        Address $destination,
        array $parcels,
        string $reference,
        array $metadata,
        ?string $shipperName = null,
    ): array {
        $phone = $destination->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('Sürat requires receiver mobile (Address::$phone).');
        }

        $city = (string) ($metadata['il'] ?? $destination->city);
        $district = (string) ($metadata['ilce'] ?? $metadata['district'] ?? $destination->state ?? '');

        if ($city === '' || $district === '') {
            throw ShippingException::carrierFailed('Sürat requires Il and Ilce.');
        }

        $weight = 0.0;
        $desi = 0.0;

        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
            $desi += max($parcel->weightKg, 1.0);
        }

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $desi = (float) ($metadata['desi'] ?? $metadata['birim_desi'] ?? max($desi, 1.0));
        $cod = (float) ($metadata['cod'] ?? $metadata['kapidan_odeme_tutar'] ?? 0);
        $webSiparis = (string) ($metadata['ozel_kargo_takip_no'] ?? $reference);
        $description = (string) ($metadata['aciklama'] ?? ((isset($parcels[0]) && is_string($parcels[0]->description)) ? $parcels[0]->description : ''));

        return [
            'KullaniciAdi' => (string) ($this->config['kullanici_adi'] ?? $this->config['cari'] ?? ''),
            'Sifre' => (string) ($this->config['sifre'] ?? ''),
            'WebSifre' => (string) ($this->config['web_sifre'] ?? ''),
            'KisiKurum' => $destination->name,
            'AliciAdresi' => trim($destination->line1.($destination->line2 !== null && $destination->line2 !== '' ? ' '.$destination->line2 : '')),
            'Il' => $city,
            'Ilce' => $district,
            'TelefonCep' => $this->normalizePhone($phone),
            'KargoTuru' => (string) ($metadata['kargo_turu'] ?? $this->config['kargo_turu'] ?? '1'),
            'OdemeTipi' => (string) ($metadata['odeme_tipi'] ?? $this->config['odeme_tipi'] ?? '1'),
            'Adet' => (string) ($metadata['adet'] ?? max(1, count($parcels))),
            'OzelKargoTakipNo' => $webSiparis,
            'BirimDesi' => (string) $desi,
            'BirimKg' => (string) $weight,
            'KapidanOdemeTutar' => $cod > 0 ? (string) $cod : '0',
            'KapidanOdeme' => $cod > 0 ? '1' : '0',
            'Gonderen' => (string) ($metadata['gonderen'] ?? $shipperName ?? ''),
            'Aciklama' => $description !== '' ? $description : 'Goods',
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
    }
}
