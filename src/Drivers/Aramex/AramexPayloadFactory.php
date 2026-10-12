<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aramex;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Aramex Shipping API V2 SOAP parameter arrays.
 *
 * Field mapping follows octw/aramex (Moustafa22/Laravel-Aramex-SDK, 31k+
 * downloads — the most battle-tested layout: ClientInfo with
 * UserName/Password/Version/AccountNumber/AccountPin/AccountEntity/
 * AccountCountryCode, Shipper/Consignee Party+Contact blocks, Details with
 * ActualWeight/ProductGroup/ProductType/PaymentType/Amounts) structured in
 * the shipbridge-aramex PayloadFactory shape (same DNA as our Bosta driver).
 *
 * Choices where the sources differ:
 * - Weight unit "KG" (octw + official manual) over shipbridge's "Kg".
 * - "Items" element omitted (octw sets it to a bare int, which does not
 *   match the manual's item list; Aramex accepts Details without it).
 * - ShippingDateTime/DueDate as ISO-8601 dateTime (shipbridge passes raw
 *   unix ints to ext-soap; over raw XML the manual's dateTime form is used).
 * - PrintLabel always sends ProductGroup + OriginEntity (required when an
 *   AWB exists more than once; harmless otherwise).
 *
 * Extra Aramex fields travel via ShipmentDraft::$metadata:
 * product_group (EXP|DOM), product_type (PPX|PDX|EPX|OND|RTRN…),
 * payment_type (P|C|3), payment_options, services (CODS auto-added when
 * cod > 0), currency, cod, description, insurance, customs_value, notes,
 * pickup_location, shipper_company, consignee_company, shipper_reference,
 * consignee_reference, label_report_id, label_report_type.
 *
 * Return/exchange travel through CreateShipments (Aramex has no dedicated
 * return API): swap the parties and set metadata.product_type to RTRN.
 */
final class AramexPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * ClientInfo auth block, required on every Aramex call.
     *
     * @return array<string, string>
     */
    public function clientInfo(): array
    {
        return [
            'UserName' => (string) ($this->config['username'] ?? $this->config['user_name'] ?? ''),
            'Password' => (string) ($this->config['password'] ?? ''),
            'Version' => (string) ($this->config['version'] ?? 'v1.0'),
            'AccountNumber' => (string) ($this->config['account_number'] ?? ''),
            'AccountPin' => (string) ($this->config['account_pin'] ?? ''),
            'AccountEntity' => (string) ($this->config['account_entity'] ?? ''),
            'AccountCountryCode' => (string) ($this->config['account_country_code'] ?? $this->config['account_country'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $shipperPhone = $draft->origin->phone ?? (isset($meta['shipper_phone']) ? (string) $meta['shipper_phone'] : '');
        $consigneePhone = $draft->destination->phone ?? (isset($meta['phone']) ? (string) $meta['phone'] : '');

        if ($shipperPhone === '' || $consigneePhone === '') {
            throw ShippingException::carrierFailed(
                'Aramex requires phone on origin and destination addresses.'
            );
        }

        $cod = (float) ($meta['cod'] ?? 0);
        $currency = strtoupper((string) ($meta['currency'] ?? $this->config['currency_code'] ?? $this->config['currency'] ?? 'USD'));
        $services = (string) ($meta['services'] ?? $this->config['services'] ?? '');

        if ($cod > 0 && $services === '') {
            $services = 'CODS';
        }

        $description = $draft->parcels[0]->description ?? (string) ($meta['description'] ?? 'Goods');
        $now = time();

        return [
            'ClientInfo' => $this->clientInfo(),
            'Transaction' => [
                'Reference1' => (string) ($draft->reference ?? ''),
                'Reference2' => '',
                'Reference3' => '',
                'Reference4' => '',
            ],
            'LabelInfo' => $this->labelInfo($meta),
            'Shipments' => [[
                'Shipper' => $this->party($draft->origin, $meta, 'shipper', $shipperPhone),
                'Consignee' => $this->party($draft->destination, $meta, 'consignee', $consigneePhone),
                'ShippingDateTime' => gmdate('Y-m-d\TH:i:s', $now),
                'DueDate' => gmdate('Y-m-d\TH:i:s', $now + 86400),
                'Comments' => (string) ($meta['notes'] ?? $meta['comments'] ?? ''),
                'PickupLocation' => (string) ($meta['pickup_location'] ?? 'Reception'),
                'Reference1' => (string) ($draft->reference ?? ''),
                'TransportType' => 0,
                'Details' => [
                    'ActualWeight' => ['Value' => $this->weightKg($draft->parcels), 'Unit' => 'KG'],
                    'ProductGroup' => (string) ($meta['product_group'] ?? $this->config['product_group'] ?? 'EXP'),
                    'ProductType' => (string) ($meta['product_type'] ?? $this->config['product_type'] ?? 'PPX'),
                    'PaymentType' => (string) ($meta['payment_type'] ?? $this->config['payment_type'] ?? 'P'),
                    'PaymentOptions' => $meta['payment_options'] ?? $this->config['payment_options'] ?? null,
                    'NumberOfPieces' => $this->pieces($draft->parcels),
                    'DescriptionOfGoods' => (string) ($meta['description'] ?? $description),
                    'GoodsOriginCountry' => strtoupper($draft->origin->countryCode),
                    'Services' => $services !== '' ? $services : null,
                    'CashOnDeliveryAmount' => ['Value' => $cod, 'CurrencyCode' => $currency],
                    'CollectAmount' => ['Value' => 0, 'CurrencyCode' => $currency],
                    'InsuranceAmount' => ['Value' => (float) ($meta['insurance'] ?? 0), 'CurrencyCode' => $currency],
                    'CustomsValueAmount' => ['Value' => (float) ($meta['customs_value'] ?? 0), 'CurrencyCode' => $currency],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function track(string $trackingNumber): array
    {
        return [
            'ClientInfo' => $this->clientInfo(),
            'Transaction' => [
                'Reference1' => '',
                'Reference2' => '',
                'Reference3' => '',
                'Reference4' => '',
            ],
            'Shipments' => [$trackingNumber],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function label(string $shipmentNumber): array
    {
        $meta = [];

        return [
            'ClientInfo' => $this->clientInfo(),
            'Transaction' => [
                'Reference1' => '',
                'Reference2' => '',
                'Reference3' => '',
                'Reference4' => '',
            ],
            'ShipmentNumber' => $shipmentNumber,
            'ProductGroup' => (string) ($this->config['product_group'] ?? 'EXP'),
            'OriginEntity' => (string) ($this->config['account_entity'] ?? ''),
            'LabelInfo' => $this->labelInfo($meta),
        ];
    }

    /**
     * CalculateRate payload (Aramex Rate Calculator service).
     *
     * Mapping follows octw's initializeCalculateRate /
     * extractCalculateRateShipmentDetails: OriginAddress +
     * DestinationAddress + ShipmentDetails(PaymentType, ProductGroup,
     * ProductType, ActualWeight, NumberOfPieces, Dimensions) +
     * PreferredCurrencyCode. The API prices one service type per call.
     *
     * @return array<string, mixed>
     */
    public function rate(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $details = [
            'PaymentType' => (string) ($meta['payment_type'] ?? $this->config['payment_type'] ?? 'P'),
            'ProductGroup' => (string) ($meta['product_group'] ?? $this->config['product_group'] ?? 'EXP'),
            'ProductType' => (string) ($meta['product_type'] ?? $this->config['product_type'] ?? 'PPX'),
            'ActualWeight' => ['Value' => $this->weightKg($draft->parcels), 'Unit' => 'KG'],
            'NumberOfPieces' => $this->pieces($draft->parcels),
        ];

        $dimensions = $this->dimensions($draft->parcels);

        if ($dimensions !== null) {
            $details['Dimensions'] = $dimensions;
        }

        return [
            'ClientInfo' => $this->clientInfo(),
            'Transaction' => [
                'Reference1' => (string) ($draft->reference ?? ''),
                'Reference2' => '',
                'Reference3' => '',
                'Reference4' => '',
            ],
            'OriginAddress' => $this->rateAddress($draft->origin),
            'DestinationAddress' => $this->rateAddress($draft->destination),
            'ShipmentDetails' => $details,
            'PreferredCurrencyCode' => strtoupper((string) ($meta['currency'] ?? $this->config['currency_code'] ?? $this->config['currency'] ?? 'USD')),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function labelInfo(array $meta): array
    {
        return [
            'ReportID' => (int) ($meta['label_report_id'] ?? $this->config['label_report_id'] ?? 9201),
            'ReportType' => (string) ($meta['label_report_type'] ?? $this->config['label_report_type'] ?? 'URL'),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function party(Address $address, array $meta, string $role, string $phone): array
    {
        $company = (string) ($meta["{$role}_company"] ?? $this->config['company_name'] ?? $address->name);
        $email = $address->email ?? (isset($meta['email']) ? (string) $meta['email'] : 'noreply@example.com');

        return [
            'Reference1' => (string) ($meta["{$role}_reference"] ?? ''),
            'AccountNumber' => (string) ($this->config['account_number'] ?? ''),
            'Contact' => [
                'PersonName' => $address->name,
                'CompanyName' => $company,
                'PhoneNumber1' => $phone,
                'CellPhone' => $phone,
                'EmailAddress' => $email,
            ],
            'PartyAddress' => [
                'Line1' => $address->line1,
                'Line2' => (string) ($address->line2 ?? ''),
                'Line3' => '',
                'City' => $address->city,
                'PostCode' => (string) ($address->postalCode ?? ''),
                'CountryCode' => strtoupper($address->countryCode),
                'StateOrProvinceCode' => (string) ($address->state ?? ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rateAddress(Address $address): array
    {
        return [
            'Line1' => $address->line1,
            'Line2' => (string) ($address->line2 ?? ''),
            'Line3' => '',
            'City' => $address->city,
            'StateOrProvinceCode' => (string) ($address->state ?? ''),
            'PostCode' => (string) ($address->postalCode ?? ''),
            'CountryCode' => strtoupper($address->countryCode),
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     */
    private function weightKg(array $parcels): float
    {
        $sum = 0.0;

        foreach ($parcels as $parcel) {
            $sum += $parcel->weightKg * max(1, $parcel->quantity);
        }

        return $sum > 0 ? $sum : 1.0;
    }

    /**
     * @param  list<Parcel>  $parcels
     */
    private function pieces(array $parcels): int
    {
        $count = 0;

        foreach ($parcels as $parcel) {
            $count += max(1, $parcel->quantity);
        }

        return max(1, $count);
    }

    /**
     * Dimensions from the first fully-dimensioned parcel, else null.
     *
     * @param  list<Parcel>  $parcels
     * @return array<string, mixed>|null
     */
    private function dimensions(array $parcels): ?array
    {
        foreach ($parcels as $parcel) {
            if ($parcel->lengthCm !== null && $parcel->widthCm !== null && $parcel->heightCm !== null) {
                return [
                    'Length' => $parcel->lengthCm,
                    'Width' => $parcel->widthCm,
                    'Height' => $parcel->heightCm,
                    'Unit' => 'CM',
                ];
            }
        }

        return null;
    }
}
