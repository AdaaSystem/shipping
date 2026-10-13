<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aras;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Aras Kargo `SetOrder` SOAP `Order` fields.
 *
 * Field names follow the live WSDL `Order` complex type — NOT the reference
 * `mohamedhekal/shipbridge-aras` payload verbatim. WSDL correction: the
 * `Order` type carries `CodAmount` / `CodCollectionType` / `CodBillingType`
 * but has NO `IsCod` and NO `SenderAccountAddressId` (those belong to the
 * `SetDispatch2` `OrderInfo` type), so they are dropped here. Returns and
 * exchanges travel through the same `SetOrder` call with
 * `metadata.shipment_kind` (`forward` default, `return`, `exchange`) and
 * `metadata.reason` folded into `Description`.
 *
 * Extra Aras fields travel via ShipmentDraft::$metadata: city, town,
 * district, phone, desi, volumetric_weight, piece_count, integration_code,
 * invoice_number, trading_waybill_number, cod, cod_amount,
 * cod_collection_type, cod_billing_type, description, tax_number,
 * tax_office, tt_document_id, phone2, phone3, special_field1..3.
 */
final class ArasPayloadFactory
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

        if ($kind !== 'forward' && isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['description'] = $metadata['reason'];
        }

        return $this->order(
            destination: $kind === 'return' ? $draft->origin : $draft->destination,
            parcels: $draft->parcels,
            reference: ($kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '')).($draft->reference ?? ''),
            metadata: $metadata,
        );
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function order(
        Address $destination,
        array $parcels,
        string $reference,
        array $metadata,
    ): array {
        $phone = $destination->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('Aras requires receiver phone (Address::$phone).');
        }

        $city = (string) ($metadata['city'] ?? $destination->city);
        $town = (string) ($metadata['town'] ?? $metadata['district'] ?? $destination->state ?? '');

        if ($city === '' || $town === '') {
            throw ShippingException::carrierFailed('Aras requires ReceiverCityName and ReceiverTownName.');
        }

        $weight = 0.0;
        $desi = 0.0;

        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
            $desi += max($parcel->weightKg, 1.0);
        }

        $desi = (float) ($metadata['desi'] ?? $metadata['volumetric_weight'] ?? $desi);

        if ($weight <= 0) {
            $weight = 1.0;
        }

        if ($desi <= 0) {
            $desi = max(1.0, $weight);
        }

        $cod = (float) ($metadata['cod'] ?? $metadata['cod_amount'] ?? 0);
        $integrationCode = (string) ($metadata['integration_code'] ?? $this->integrationCode($reference));
        $description = (string) ($metadata['description'] ?? ((isset($parcels[0]) && is_string($parcels[0]->description)) ? $parcels[0]->description : ''));

        $payload = [
            'UserName' => (string) ($this->config['username'] ?? ''),
            'Password' => (string) ($this->config['password'] ?? ''),
            'TradingWaybillNumber' => (string) ($metadata['trading_waybill_number'] ?? ($reference !== '' ? $reference : $integrationCode)),
            'InvoiceNumber' => (string) ($metadata['invoice_number'] ?? ($reference !== '' ? $reference : $integrationCode)),
            'ReceiverName' => $destination->name,
            'ReceiverAddress' => trim($destination->line1.($destination->line2 !== null && $destination->line2 !== '' ? ' '.$destination->line2 : '')),
            'ReceiverPhone1' => $this->normalizePhone($phone),
            'ReceiverPhone2' => (string) ($metadata['phone2'] ?? ''),
            'ReceiverPhone3' => (string) ($metadata['phone3'] ?? ''),
            'ReceiverCityName' => $city,
            'ReceiverTownName' => $town,
            'VolumetricWeight' => (string) $desi,
            'Weight' => (string) $weight,
            'PieceCount' => (string) ($metadata['piece_count'] ?? max(1, count($parcels))),
            'CodAmount' => $cod > 0 ? (string) $cod : '0',
            'IntegrationCode' => $integrationCode,
            'Description' => $description !== '' ? $description : 'Goods',
            'TaxNumber' => (string) ($metadata['tax_number'] ?? ''),
            'TaxOffice' => (string) ($metadata['tax_office'] ?? ''),
            'TtDocumentId' => (string) ($metadata['tt_document_id'] ?? ''),
        ];

        foreach (['special_field1' => 'SpecialField1', 'special_field2' => 'SpecialField2', 'special_field3' => 'SpecialField3'] as $metaKey => $field) {
            $value = (string) ($metadata[$metaKey] ?? '');

            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($cod > 0) {
            $payload['CodCollectionType'] = (string) ($metadata['cod_collection_type'] ?? '0');
            $payload['CodBillingType'] = (string) ($metadata['cod_billing_type'] ?? '0');
        }

        return $payload;
    }

    private function integrationCode(string $reference): string
    {
        $prefix = (string) ($this->config['integration_code_prefix'] ?? 'SB');
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?: (string) time());

        return substr($prefix.$base, 0, 32);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
    }
}
