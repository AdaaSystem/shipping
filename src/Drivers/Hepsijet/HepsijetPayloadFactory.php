<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Hepsijet;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → HepsiJet `sendDeliveryOrderEnhanced` JSON.
 *
 * Field shapes follow the reference `mohamedhekal/shipbridge-hepsijet`
 * `Support/PayloadFactory` source (company / delivery / currentXDock,
 * recipient + sender/recipient addresses, deliveryContent). Returns,
 * exchanges, and COD travel through the same create call:
 * `metadata.shipment_kind` (`forward` default, `return`, `exchange`) selects
 * `deliveryType` (`RETAIL` vs `RETURNED`) with `metadata.reason` as the
 * content note — the same via-create convention as the sibling drivers.
 *
 * Extra HepsiJet fields travel via ShipmentDraft::$metadata: town,
 * district, neighborhood, mahalle, desi, width, height, length, cod /
 * cod_amount, payment_type, delivery_slot, delivery_date, delivery_type,
 * product_code, content.
 */
final class HepsijetPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return array{payload: array<string, mixed>, customer_delivery_no: string, shipment_kind: string}
     */
    public function create(ShipmentDraft $draft): array
    {
        $metadata = $draft->metadata;
        $kind = strtolower((string) ($metadata['shipment_kind'] ?? 'forward'));

        if (! in_array($kind, ['forward', 'return', 'exchange'], true)) {
            $kind = 'forward';
        }

        $prefix = $kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '');
        $reference = $prefix.($draft->reference ?? uniqid('SB', true));

        if (isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['content'] = $metadata['reason'];
        }

        // Returns are collected from the origin party and routed back.
        $origin = $kind === 'return' ? $draft->destination : $draft->origin;
        $destination = $kind === 'return' ? $draft->origin : $draft->destination;

        $customerDeliveryNo = $this->customerDeliveryNo($reference);
        $deliveryType = $kind === 'return' ? 'RETURNED' : 'RETAIL';

        $forward = new ShipmentDraft(
            origin: $origin,
            destination: $destination,
            parcels: $draft->parcels,
            reference: $reference,
            metadata: $metadata,
            idempotencyKey: $draft->idempotencyKey,
        );

        return [
            'payload' => $this->orderBody($forward, $customerDeliveryNo, $deliveryType),
            'customer_delivery_no' => $customerDeliveryNo,
            'shipment_kind' => $kind,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function track(string $barcode, bool $withHistory = true): array
    {
        return [
            'barcodes' => [$barcode],
            'isTrackAdded' => $withHistory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderBody(ShipmentDraft $draft, string $customerDeliveryNo, string $deliveryType): array
    {
        $destination = $draft->destination;
        $meta = $draft->metadata;
        $parcels = $draft->parcels;
        $totalParcels = max(1, count($parcels));
        $totalWeight = 0.0;
        $totalDesi = (float) ($meta['desi'] ?? 0);

        foreach ($parcels as $parcel) {
            $totalWeight += $parcel->weightKg;

            if ($totalDesi <= 0) {
                $totalDesi += max(1.0, $parcel->weightKg);
            }
        }

        if ($totalDesi <= 0) {
            $totalDesi = 1.0;
        }

        if ($totalWeight <= 0) {
            $totalWeight = 1.0;
        }

        $phone = (string) ($destination->phone ?? '');

        if ($phone === '') {
            throw ShippingException::carrierFailed('HepsiJet requires receiver phone (Address::$phone).');
        }

        $codAmount = (float) ($meta['cod'] ?? $meta['cod_amount'] ?? 0);
        $paymentType = (string) ($meta['payment_type'] ?? '');
        $deliveryTotalPrice = $paymentType === 'RECEIVER' || $codAmount > 0 ? $codAmount : 0;

        $delivery = [
            'customerDeliveryNo' => $customerDeliveryNo,
            'customerOrderId' => $customerDeliveryNo,
            'totalParcels' => $totalParcels,
            'desi' => $totalDesi,
            'weight' => $totalWeight,
            'width' => (int) ($meta['width'] ?? 0),
            'height' => (int) ($meta['height'] ?? 0),
            'length' => (int) ($meta['length'] ?? 0),
            'deliveryTotalPrice' => $deliveryTotalPrice,
            'deliverySlotOriginal' => (int) ($meta['delivery_slot'] ?? 0),
            'deliveryDateOriginal' => (string) ($meta['delivery_date'] ?? date('Y-m-d')),
            'deliveryType' => (string) ($meta['delivery_type'] ?? $deliveryType),
            'recipientPerson' => $destination->name,
            'recipientPersonPhone1' => $this->normalizePhone($phone),
            'product' => [
                'productCode' => (string) ($meta['product_code'] ?? $this->config['product_code'] ?? 'HX_STD'),
            ],
            'receiver' => $this->receiver($destination),
            'senderAddress' => $this->senderAddress($draft->origin),
            'recipientAddress' => $this->recipientAddress($destination, $meta),
            'deliveryContent' => $this->deliveryContent($parcels, $meta),
        ];

        return [
            'company' => [
                'name' => (string) ($this->config['company_name'] ?? ''),
                'abbreviationCode' => (string) ($this->config['abbreviation_code'] ?? ''),
            ],
            'delivery' => $delivery,
            'currentXDock' => [
                'abbreviationCode' => (string) ($this->config['current_xdock_code'] ?? ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiver(Address $address): array
    {
        [$first, $last] = $this->splitName($address->name);

        $receiver = [
            'firstName' => $first,
            'lastName' => $last,
            'phone1' => $this->normalizePhone((string) ($address->phone ?? '')),
        ];

        if ($address->email !== null && $address->email !== '') {
            $receiver['email'] = $address->email;
        }

        return $receiver;
    }

    /**
     * @return array<string, mixed>
     */
    private function senderAddress(Address $origin): array
    {
        $sender = [
            'companyAddressId' => (string) ($this->config['company_address_id'] ?? ''),
            'country' => ['name' => 'Türkiye'],
            'city' => ['name' => $origin->city],
            'town' => ['name' => (string) ($origin->state ?? '')],
            'district' => ['name' => ''],
        ];

        $line = trim($origin->line1.($origin->line2 !== null && $origin->line2 !== '' ? ' '.$origin->line2 : ''));

        if ($line !== '') {
            $sender['addressLine1'] = $line;
        }

        return $sender;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function recipientAddress(Address $address, array $metadata): array
    {
        $town = (string) ($metadata['town'] ?? $metadata['district'] ?? $address->state ?? '');
        $district = (string) ($metadata['neighborhood'] ?? $metadata['mahalle'] ?? '');

        return [
            'addressLine1' => trim($address->line1.($address->line2 !== null && $address->line2 !== '' ? ' '.$address->line2 : '')),
            'country' => ['name' => 'Türkiye'],
            'city' => ['name' => $address->city],
            'town' => ['name' => $town],
            'district' => ['name' => $district],
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function deliveryContent(array $parcels, array $metadata): array
    {
        if ($parcels === []) {
            return [[
                'sku' => 'PKG-1',
                'description' => (string) ($metadata['content'] ?? '-'),
                'quantity' => 1,
            ]];
        }

        $content = [];
        $index = 1;

        foreach ($parcels as $parcel) {
            $content[] = [
                'sku' => 'PKG-'.$index,
                'description' => $parcel->description !== null && $parcel->description !== ''
                    ? $parcel->description
                    : (string) ($metadata['content'] ?? '-'),
                'quantity' => 1,
            ];
            $index++;
        }

        return $content;
    }

    private function customerDeliveryNo(string $reference): string
    {
        $abbrev = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($this->config['abbreviation_code'] ?? '')) ?? '');

        if ($abbrev === '') {
            throw ShippingException::carrierFailed('HepsiJet requires abbreviation_code in per-connection config.');
        }

        $suffix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $reference) ?? '');

        if ($suffix === '') {
            $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $maxLen = 16;
        $minLen = 9;
        $abbrev = substr($abbrev, 0, $maxLen);
        $available = max(1, $maxLen - strlen($abbrev));
        $suffix = substr($suffix, 0, $available);

        $no = substr($abbrev.$suffix, 0, $maxLen);

        while (strlen($no) < $minLen) {
            $no .= '0';
        }

        if (strlen($no) < $minLen) {
            throw ShippingException::carrierFailed('HepsiJet customerDeliveryNo must be 9–16 characters.');
        }

        return $no;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        if ($parts === false || count($parts) < 2) {
            return [$fullName, ''];
        }

        return [$parts[0], $parts[1]];
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
