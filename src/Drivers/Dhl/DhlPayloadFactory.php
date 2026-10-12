<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Dhl;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → DHL Express MyDHL REST payloads.
 *
 * Field mapping follows sonnenglas/mydhl-php-sdk (spec 3.3.1 — the
 * fullest open-source model: ShipmentRequest::toQuery,
 * RateRequest::toQuery, MultiPieceRateRequest::toQuery) structured in the
 * shipbridge-dhl PayloadFactory shape (same DNA as our Bosta driver:
 * partyDetails/packages/plannedShippingDate helpers, metadata overrides).
 *
 * Choices where the sources differ:
 * - Shipment shape from sonnenglas (authoritative: validated against the
 *   spec; description 1–70 chars + incoterm required on EVERY shipment,
 *   phone required on both parties) over shipbridge-dhl's looser map.
 * - Multi-parcel drafts expand Parcel::$quantity into repeated package
 *   entries (DHL packages are per piece; the SDK has no quantity field).
 * - Missing dimensions default to 10cm (shipbridge-dhl) since DHL
 *   Package requires positive length/width/height.
 *
 * Extra DHL fields travel via ShipmentDraft::$metadata:
 * product_code, account_number, account_type, incoterm, description,
 * is_customs_declarable, declared_value (+ declared_value_currency or
 * currency), unit_of_measurement, pickup_requested, planned_shipping_date
 * (or shipping_date), reference_type, shipper_/receiver_ party overrides
 * (company, phone, email, city, postal_code, country_code, county/state).
 */
final class DhlPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * POST /shipments body.
     *
     * @return array<string, mixed>
     */
    public function create(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $accountNumber = $this->accountNumber($meta);

        $shipperPhone = $draft->origin->phone ?? (isset($meta['shipper_phone']) ? (string) $meta['shipper_phone'] : '');
        $consigneePhone = $draft->destination->phone ?? (isset($meta['phone']) ? (string) $meta['phone'] : '');

        if ($shipperPhone === '' || $consigneePhone === '') {
            throw ShippingException::carrierFailed(
                'DHL requires phone on origin and destination addresses.'
            );
        }

        $description = trim((string) ($meta['description'] ?? $draft->parcels[0]->description ?? 'Goods'));

        if ($description === '') {
            throw ShippingException::carrierFailed(
                'DHL requires a shipment description (1-70 characters).'
            );
        }

        if (strlen($description) > 70) {
            $description = substr($description, 0, 70);
        }

        $payload = [
            'plannedShippingDateAndTime' => $this->plannedShippingDateTime($meta),
            'pickup' => [
                'isRequested' => (bool) ($meta['pickup_requested'] ?? $this->config['pickup_requested'] ?? false),
            ],
            'productCode' => (string) ($meta['product_code'] ?? $this->config['product_code'] ?? 'P'),
            'accounts' => [[
                'typeCode' => (string) ($meta['account_type'] ?? 'shipper'),
                'number' => $accountNumber,
            ]],
            'customerDetails' => [
                'shipperDetails' => $this->party($draft->origin, $meta, 'shipper'),
                'receiverDetails' => $this->party($draft->destination, $meta, 'receiver'),
            ],
            'content' => array_filter([
                'packages' => $this->packages($draft->parcels),
                'isCustomsDeclarable' => (bool) ($meta['is_customs_declarable'] ?? false),
                'description' => $description,
                'incoterm' => strtoupper((string) ($meta['incoterm'] ?? 'DAP')),
                'unitOfMeasurement' => (string) ($meta['unit_of_measurement'] ?? 'metric'),
                'declaredValue' => $this->declaredValue($meta),
                'declaredValueCurrency' => $this->declaredCurrency($meta),
            ], static fn (mixed $value): bool => $value !== null),
            'getRateEstimates' => false,
        ];

        if ($draft->reference !== null && $draft->reference !== '') {
            $payload['customerReferences'] = [[
                'value' => $draft->reference,
                'typeCode' => (string) ($meta['reference_type'] ?? 'CU'),
            ]];
        }

        return $payload;
    }

    /**
     * GET /rates query (one-piece shipments).
     *
     * @return array<string, mixed>
     */
    public function rate(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $parcel = $draft->parcels[0] ?? new Parcel(weightKg: 1.0);

        return [
            'accountNumber' => $this->accountNumber($meta),
            'originCountryCode' => strtoupper($draft->origin->countryCode),
            'originPostalCode' => (string) ($draft->origin->postalCode ?? ''),
            'originCityName' => $draft->origin->city,
            'destinationCountryCode' => strtoupper($draft->destination->countryCode),
            'destinationPostalCode' => (string) ($draft->destination->postalCode ?? ''),
            'destinationCityName' => $draft->destination->city,
            'weight' => (string) ($parcel->weightKg > 0 ? $parcel->weightKg : 1.0),
            'length' => (string) ($parcel->lengthCm ?? 10),
            'width' => (string) ($parcel->widthCm ?? 10),
            'height' => (string) ($parcel->heightCm ?? 10),
            'plannedShippingDate' => $this->plannedShippingDate($meta),
            'isCustomsDeclarable' => ($meta['is_customs_declarable'] ?? false) ? 'true' : 'false',
            'unitOfMeasurement' => (string) ($meta['unit_of_measurement'] ?? 'metric'),
            'nextBusinessDay' => 'false',
        ];
    }

    /**
     * POST /rates body (multi-piece shipments — query parameters cannot
     * carry a list of packages).
     *
     * @return array<string, mixed>
     */
    public function rateMulti(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;

        return array_filter([
            'customerDetails' => [
                'shipperDetails' => $this->rateAddress($draft->origin),
                'receiverDetails' => $this->rateAddress($draft->destination),
            ],
            'accounts' => [[
                'typeCode' => (string) ($meta['account_type'] ?? 'shipper'),
                'number' => $this->accountNumber($meta),
            ]],
            'packages' => $this->packages($draft->parcels),
            'plannedShippingDateAndTime' => $this->plannedShippingDateTime($meta),
            'unitOfMeasurement' => (string) ($meta['unit_of_measurement'] ?? 'metric'),
            'isCustomsDeclarable' => (bool) ($meta['is_customs_declarable'] ?? false),
            'nextBusinessDay' => false,
            'productCode' => isset($meta['product_code']) ? (string) $meta['product_code'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function party(Address $address, array $meta, string $role): array
    {
        $prefix = $role.'_';
        $name = (string) ($meta[$prefix.'name'] ?? $address->name);
        $phone = (string) ($meta[$prefix.'phone'] ?? $address->phone ?? '');
        $email = (string) ($meta[$prefix.'email'] ?? $address->email ?? '');

        $contact = [
            'phone' => $phone,
            'companyName' => (string) ($meta[$prefix.'company'] ?? $name),
            'fullName' => $name,
        ];

        if ($email !== '') {
            $contact['email'] = $email;
        }

        $postalAddress = [
            'addressLine1' => trim($address->line1.($address->line2 !== null && $address->line2 !== '' ? ', '.$address->line2 : '')),
            'countryCode' => strtoupper((string) ($meta[$prefix.'country_code'] ?? $address->countryCode)),
            'postalCode' => (string) ($meta[$prefix.'postal_code'] ?? $address->postalCode ?? ''),
            'cityName' => (string) ($meta[$prefix.'city'] ?? $address->city),
        ];

        $county = $meta[$prefix.'county'] ?? $meta[$prefix.'state'] ?? $address->state;

        if (is_string($county) && $county !== '') {
            $postalAddress['countyName'] = $county;
        }

        return [
            'postalAddress' => $postalAddress,
            'contactInformation' => $contact,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rateAddress(Address $address): array
    {
        return [
            'postalCode' => (string) ($address->postalCode ?? ''),
            'cityName' => $address->city,
            'countryCode' => strtoupper($address->countryCode),
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @return list<array<string, mixed>>
     */
    private function packages(array $parcels): array
    {
        if ($parcels === []) {
            $parcels = [new Parcel(weightKg: 1.0)];
        }

        $rows = [];

        foreach ($parcels as $parcel) {
            $repeat = max(1, $parcel->quantity);

            for ($i = 0; $i < $repeat; $i++) {
                $rows[] = [
                    'weight' => $parcel->weightKg > 0 ? $parcel->weightKg : 1.0,
                    'dimensions' => [
                        'length' => $parcel->lengthCm !== null && $parcel->lengthCm > 0 ? $parcel->lengthCm : 10,
                        'width' => $parcel->widthCm !== null && $parcel->widthCm > 0 ? $parcel->widthCm : 10,
                        'height' => $parcel->heightCm !== null && $parcel->heightCm > 0 ? $parcel->heightCm : 10,
                    ],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function accountNumber(array $meta): string
    {
        $account = (string) ($meta['account_number'] ?? $this->config['account_number'] ?? '');

        if ($account === '') {
            throw ShippingException::carrierFailed(
                'DHL requires a shipper account number (metadata.account_number).'
            );
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function declaredValue(array $meta): ?float
    {
        $value = $meta['declared_value'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function declaredCurrency(array $meta): ?string
    {
        $currency = $meta['declared_value_currency'] ?? $meta['currency'] ?? null;

        if (! is_string($currency) || $currency === '') {
            return null;
        }

        return strtoupper($currency);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function plannedShippingDateTime(array $meta): string
    {
        foreach (['planned_shipping_date', 'shipping_date'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && $meta[$key] !== '') {
                return $meta[$key];
            }
        }

        return gmdate('Y-m-d\TH:i:s', time() + 86400).' GMT+00:00';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function plannedShippingDate(array $meta): string
    {
        $dateTime = $this->plannedShippingDateTime($meta);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateTime) === 1) {
            return substr($dateTime, 0, 10);
        }

        return gmdate('Y-m-d', time() + 86400);
    }
}
