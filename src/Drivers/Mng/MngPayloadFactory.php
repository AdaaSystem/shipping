<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mng;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → MNG Kargo ApiZone JSON payloads.
 *
 * Field shapes follow the reference `mohamedhekal/shipbridge-mng`
 * `Support/PayloadFactory` source (order / orderPieceList / recipient,
 * barcode envelope). Returns, exchanges, and COD travel through the same
 * create call: `metadata.shipment_kind` (`forward` default, `return`,
 * `exchange`) with `metadata.reason` as the return/exchange content note —
 * the same via-create convention as the Bosta/SMSA/J&T drivers.
 *
 * Extra MNG fields travel via ShipmentDraft::$metadata: city_code,
 * district_code, district, tax_number, cod / is_cod / cod_amount,
 * create_barcode, desi, packaging_type, shipment_service_type,
 * delivery_type, payment_type, bill_of_landing_id, content, description,
 * marketplace_short_code, marketplace_sale_code.
 */
final class MngPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return array{order: array<string, mixed>, barcode: array<string, mixed>|null, reference_id: string, create_barcode: bool, shipment_kind: string}
     */
    public function create(ShipmentDraft $draft): array
    {
        $metadata = $draft->metadata;
        $kind = strtolower((string) ($metadata['shipment_kind'] ?? 'forward'));

        if (! in_array($kind, ['forward', 'return', 'exchange'], true)) {
            $kind = 'forward';
        }

        $prefix = $kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '');
        $referenceId = $this->normalizeReference($prefix.($draft->reference ?? uniqid('SB', true)));

        // Returns are collected from the origin party; the recipient block
        // still describes the party MNG routes the return paperwork to.
        $recipientAddress = $kind === 'return' ? $draft->origin : $draft->destination;

        if ($kind === 'return' && isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['content'] = $metadata['reason'];
            $metadata['description'] = $metadata['reason'];
        }

        if ($kind === 'exchange' && isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['content'] = $metadata['reason'];
            $metadata['description'] = $metadata['reason'];
        }

        $pieces = $this->pieces($referenceId, $draft->parcels, $metadata);
        $order = $this->orderBody($referenceId, $draft->parcels, $metadata);
        $recipient = $this->recipient($recipientAddress, $metadata);

        $createBarcode = array_key_exists('create_barcode', $metadata)
            ? (bool) $metadata['create_barcode']
            : (bool) ($this->config['create_barcode'] ?? true);

        $barcode = null;

        if ($createBarcode) {
            $barcode = [
                'referenceId' => $referenceId,
                'billOfLandingId' => (string) ($metadata['bill_of_landing_id'] ?? ''),
                'isCOD' => $order['isCOD'],
                'codAmount' => $order['codAmount'],
                'printReferenceBarcodeOnError' => 1,
                'message' => '',
                'additionalContent1' => '',
                'additionalContent2' => '',
                'additionalContent3' => '',
                'additionalContent4' => '',
                'orderPieceList' => $pieces,
                'packagingType' => $order['packagingType'],
            ];
        }

        return [
            'order' => [
                'order' => $order,
                'orderPieceList' => $pieces,
                'recipient' => $recipient,
            ],
            'barcode' => $barcode,
            'reference_id' => $referenceId,
            'create_barcode' => $createBarcode,
            'shipment_kind' => $kind,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function recipient(Address $address, array $metadata): array
    {
        $cityCode = (int) ($metadata['city_code'] ?? $metadata['recipient_city_code'] ?? 0);
        $districtCode = (int) ($metadata['district_code'] ?? $metadata['recipient_district_code'] ?? 0);
        $districtName = (string) ($metadata['district'] ?? $address->state ?? '');

        if ($cityCode === 0 && $address->city === '') {
            throw ShippingException::carrierFailed('MNG requires metadata.city_code (CBS) or a city name.');
        }

        $email = $address->email !== null && $address->email !== ''
            ? $address->email
            : (string) ($this->config['fallback_email'] ?? 'noreply@example.com');
        $tax = (string) ($metadata['tax_number'] ?? $metadata['recipient_tax_number'] ?? $this->config['fallback_tax_number'] ?? '11111111110');

        return [
            'refCustomerId' => '',
            'cityCode' => $cityCode,
            'cityName' => $address->city,
            'districtCode' => $districtCode,
            'districtName' => $districtName,
            'address' => trim($address->line1.($address->line2 !== null && $address->line2 !== '' ? ' '.$address->line2 : '')),
            'bussinessPhoneNumber' => '',
            'email' => $email,
            'taxOffice' => 'SAHIS',
            'taxNumber' => $tax,
            'fullName' => $address->name,
            'homePhoneNumber' => '',
            'mobilePhoneNumber' => $this->normalizePhone((string) ($address->phone ?? '')),
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function orderBody(string $referenceId, array $parcels, array $metadata): array
    {
        $content = (string) ($metadata['content'] ?? $metadata['description'] ?? $this->contentSummary($parcels));
        $codAmount = (float) ($metadata['cod'] ?? $metadata['cod_amount'] ?? 0);
        $isCod = (int) ((bool) ($metadata['is_cod'] ?? $metadata['cod'] ?? false) || $codAmount > 0);

        return [
            'referenceId' => $referenceId,
            'barcode' => $referenceId,
            'billOfLandingId' => (string) ($metadata['bill_of_landing_id'] ?? ''),
            'isCOD' => $isCod,
            'codAmount' => $isCod === 1 ? (int) round($codAmount) : 0,
            'shipmentServiceType' => (int) ($metadata['shipment_service_type'] ?? $this->config['shipment_service_type'] ?? 1),
            'packagingType' => (int) ($metadata['packaging_type'] ?? $this->config['packaging_type'] ?? 3),
            'content' => $content !== '' ? $content : '-',
            'smsPreference1' => 0,
            'smsPreference2' => 0,
            'smsPreference3' => 0,
            'paymentType' => (int) ($metadata['payment_type'] ?? $this->config['payment_type'] ?? 1),
            'deliveryType' => (int) ($metadata['delivery_type'] ?? $this->config['delivery_type'] ?? 1),
            'description' => (string) ($metadata['description'] ?? $content),
            'marketPlaceShortCode' => (string) ($metadata['marketplace_short_code'] ?? ''),
            'marketPlaceSaleCode' => (string) ($metadata['marketplace_sale_code'] ?? ''),
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function pieces(string $referenceId, array $parcels, array $metadata): array
    {
        if ($parcels === []) {
            return [[
                'barcode' => $referenceId.'_PARCA1',
                'desi' => (int) ($metadata['desi'] ?? 1),
                'kg' => 1,
                'content' => '-',
            ]];
        }

        $pieces = [];
        $index = 1;

        foreach ($parcels as $parcel) {
            $desi = (int) round((float) ($metadata['desi'] ?? ($parcel->weightKg > 0 ? $parcel->weightKg : 1)));
            $pieces[] = [
                'barcode' => $referenceId.'_PARCA'.$index,
                'desi' => max(1, $desi),
                'kg' => max(1, (int) round($parcel->weightKg)),
                'content' => $parcel->description !== null && $parcel->description !== '' ? $parcel->description : '-',
            ];
            $index++;
        }

        return $pieces;
    }

    /**
     * @param  list<Parcel>  $parcels
     */
    private function contentSummary(array $parcels): string
    {
        foreach ($parcels as $parcel) {
            if ($parcel->description !== null && $parcel->description !== '') {
                return $parcel->description;
            }
        }

        return '-';
    }

    private function normalizeReference(string $reference): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9\-_]/i', '', $reference) ?? $reference);
        $normalized = substr($normalized, 0, 30);

        if ($normalized === '') {
            throw ShippingException::carrierFailed('MNG referenceId cannot be empty.');
        }

        return $normalized;
    }

    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($cleaned, '90') && strlen($cleaned) === 12) {
            $cleaned = substr($cleaned, 2);
        }

        if (str_starts_with($cleaned, '0') && strlen($cleaned) === 11) {
            $cleaned = substr($cleaned, 1);
        }

        return $cleaned;
    }
}
