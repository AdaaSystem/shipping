<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Yurtici;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Yurtiçi Kargo `ShippingOrderVO` SOAP parameters.
 *
 * Field shapes follow the reference `mohamedhekal/shipbridge-yurtici`
 * `Support/PayloadFactory` source, corrected with the independent
 * `tcgunel/omniship-yurtici` source (see client docblock): numeric
 * `taxOfficeId` / `ttDocumentId` / `dcSelectedCredit` / `dcCreditRule`
 * default to 0, `desi`/`kg` are omitted when unknown, `taxNumber` is only
 * sent as 10–11 digits. Returns and exchanges travel through the same
 * `createShipment` call with `metadata.shipment_kind` (`forward` default,
 * `return`, `exchange`) — the pickup party is folded into `Description`
 * for returns.
 *
 * Extra Yurtiçi fields travel via ShipmentDraft::$metadata: cargo_key,
 * invoice_key, city, town, district, phone, phone2, phone3, email,
 * tax_number, tax_office_id, tax_office_name, desi, cargo_count, waybill_no,
 * description, special_field1..3, org_receiver_cust_id, cod / cod_amount /
 * is_cod, tt_collection_type, tt_document_id, tt_document_save_type,
 * dc_selected_credit, dc_credit_rule, key_type.
 */
final class YurticiPayloadFactory
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

        $reference = ($kind === 'return' ? 'RTN-' : ($kind === 'exchange' ? 'EXC-' : '')).($draft->reference ?? uniqid('SB', true));
        $shipperNote = null;

        if ($kind === 'return') {
            $shipperNote = $this->addressLine($draft->origin);

            if (isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
                $metadata['description'] = $metadata['reason'];
            }
        }

        if ($kind === 'exchange' && isset($metadata['reason']) && is_string($metadata['reason']) && $metadata['reason'] !== '') {
            $metadata['description'] = $metadata['reason'];
        }

        return $this->authParams(false) + [
            'ShippingOrderVO' => [$this->shippingOrder(
                $kind === 'return' ? $draft->origin : $draft->destination,
                $draft->parcels,
                $reference,
                $metadata,
                $shipperNote,
            )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $cargoKey, int $keyType = 0, bool $historical = true): array
    {
        return $this->authParams(true) + [
            'keys' => [$cargoKey],
            'keyType' => $keyType,
            'addHistoricalData' => $historical,
            'onlyTracking' => false,
        ];
    }

    /**
     * @param  string|list<string>  $cargoKeys
     * @return array<string, mixed>
     */
    public function cancel(string|array $cargoKeys): array
    {
        return $this->authParams(false) + [
            'cargoKeys' => is_array($cargoKeys) ? array_values($cargoKeys) : [$cargoKeys],
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function shippingOrder(Address $consignee, array $parcels, string $reference, array $metadata, ?string $shipperNote = null): array
    {
        $phone = (string) ($consignee->phone ?? $metadata['phone'] ?? '');

        if ($phone === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo requires consignee phone (Address::$phone).');
        }

        $city = (string) ($metadata['city'] ?? $consignee->city);

        if ($city === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo requires cityName.');
        }

        $town = (string) ($metadata['town'] ?? $metadata['district'] ?? $consignee->state ?? '');
        $cargoKey = substr((string) ($metadata['cargo_key'] ?? $this->normalizeKey($reference)), 0, 20);
        $invoiceKey = substr((string) ($metadata['invoice_key'] ?? $cargoKey), 0, 20);

        $totalKg = 0.0;
        $totalDesi = (float) ($metadata['desi'] ?? 0);
        $count = 0;

        foreach ($parcels as $parcel) {
            $totalKg += $parcel->weightKg * max(1, $parcel->quantity);
            $count += max(1, $parcel->quantity);

            if ($totalDesi <= 0) {
                $totalDesi += max(1.0, $parcel->weightKg);
            }
        }

        $description = (string) ($metadata['description'] ?? $this->contentSummary($parcels));

        if ($shipperNote !== null && $shipperNote !== '') {
            $description = trim($description.' '.$shipperNote);
        }

        $order = [
            'cargoKey' => $cargoKey,
            'invoiceKey' => $invoiceKey,
            'receiverCustName' => mb_substr($consignee->name, 0, 200),
            'receiverAddress' => $this->addressLine($consignee),
            'receiverPhone1' => $this->normalizePhone($phone),
            'receiverPhone2' => (string) ($metadata['phone2'] ?? ''),
            'receiverPhone3' => (string) ($metadata['phone3'] ?? ''),
            'cityName' => mb_substr($city, 0, 40),
            'townName' => mb_substr($town, 0, 40),
            'emailAddress' => mb_substr((string) ($metadata['email'] ?? $consignee->email ?? ''), 0, 200),
            'taxOfficeId' => (int) ($metadata['tax_office_id'] ?? 0),
            'taxOfficeName' => (string) ($metadata['tax_office_name'] ?? ''),
            'cargoCount' => max(1, (int) ($metadata['cargo_count'] ?? $count)),
            'waybillNo' => (string) ($metadata['waybill_no'] ?? ''),
            'description' => mb_substr($description, 0, 255),
            'specialField1' => (string) ($metadata['special_field1'] ?? ''),
            'specialField2' => (string) ($metadata['special_field2'] ?? ''),
            'specialField3' => (string) ($metadata['special_field3'] ?? ''),
            'ttDocumentId' => 0,
            'dcSelectedCredit' => (int) ($metadata['dc_selected_credit'] ?? 0),
            'dcCreditRule' => (int) ($metadata['dc_credit_rule'] ?? 0),
            'orgReceiverCustId' => mb_substr((string) ($metadata['org_receiver_cust_id'] ?? ''), 0, 50),
        ];

        $taxNumber = preg_replace('/\D+/', '', (string) ($metadata['tax_number'] ?? '')) ?? '';

        if (in_array(strlen($taxNumber), [10, 11], true)) {
            $order['taxNumber'] = $taxNumber;
        }

        if ($totalDesi > 0) {
            $order['desi'] = round($totalDesi, 3);
        }

        if ($totalKg > 0) {
            $order['kg'] = round($totalKg, 3);
        }

        $codAmount = (float) ($metadata['cod'] ?? $metadata['cod_amount'] ?? 0);

        if ($codAmount > 0 || (bool) ($metadata['is_cod'] ?? false)) {
            $order['ttInvoiceAmount'] = number_format($codAmount, 2, '.', '');
            $order['ttCollectionType'] = (string) ($metadata['tt_collection_type'] ?? $metadata['cod_collection_type'] ?? '0');
            $order['ttDocumentSaveType'] = (string) ($metadata['tt_document_save_type'] ?? $metadata['cod_document_save_type'] ?? '0');

            $documentId = preg_replace('/\D+/', '', (string) ($metadata['tt_document_id'] ?? $metadata['cod_document_id'] ?? '')) ?? '';
            $order['ttDocumentId'] = $documentId === '' ? 0 : (int) substr($documentId, 0, 12);

            if ($order['ttCollectionType'] === '1') {
                $order['dcSelectedCredit'] = max(1, (int) ($metadata['dc_selected_credit'] ?? 1));
                $order['dcCreditRule'] = (int) ($metadata['dc_credit_rule'] ?? 1) === 0 ? 0 : 1;
            }
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function authParams(bool $query): array
    {
        $username = (string) ($this->config['ws_user_name'] ?? $this->config['wsUserName'] ?? $this->config['username'] ?? '');
        $password = (string) ($this->config['ws_password'] ?? $this->config['wsPassword'] ?? $this->config['password'] ?? '');
        $language = (string) ($this->config['ws_language'] ?? $this->config['wsLanguage'] ?? $this->config['userLanguage'] ?? 'TR');

        if ($username === '' || $password === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo requires ws_user_name and ws_password (per-connection credentials).');
        }

        if ($query) {
            return [
                'wsUserName' => $username,
                'wsPassword' => $password,
                'wsLanguage' => $language,
            ];
        }

        return [
            'wsUserName' => $username,
            'wsPassword' => $password,
            'userLanguage' => $language,
        ];
    }

    private function addressLine(Address $address): string
    {
        return trim($address->line1.($address->line2 !== null && $address->line2 !== '' ? ' '.$address->line2 : ''));
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

        return 'Goods';
    }

    private function normalizeKey(string $reference): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9\-_]/i', '', $reference) ?? $reference);
        $normalized = substr($normalized, 0, 20);

        if ($normalized === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo cargoKey cannot be empty.');
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
