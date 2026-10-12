<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ups;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → UPS REST JSON payloads.
 *
 * Source comparison (both fetched and compared):
 * - gabrielbull/php-ups-api (mature field knowledge, legacy auth): reused
 *   where still valid on REST — Service codes (01/02/03/07/08/11/12/13/14/
 *   54/59/65/…), PackagingType codes (02 customer-supplied package default),
 *   Prepaid PaymentInformation with BillShipper AccountNumber, Package
 *   PackageWeight + Dimensions + Description, Shipper/ShipTo/ShipFrom party
 *   blocks, ShipmentServiceOptions COD (CODFundsCode + CODAmount), and
 *   RateInformation/ShipmentRatingOptions NegotiatedRatesIndicator. Its
 *   two-step confirm (ShipConfirm) + accept (ShipAccept) flow does NOT apply:
 *   REST creates + labels in one POST /api/shipments/{v}/ship call.
 * - mohamedhekal/shipbridge-ups (OAuth2 REST, the auth this driver adopts):
 *   ShipmentRequest envelope (RequestOption nonvalidate, LabelSpecification
 *   LabelImageFormat), OAuth2 client-credentials + transId/transactionSrc
 *   headers, per-package TrackingNumber under
 *   ShipmentResponse.ShipmentResults.PackageResults, label bytes under
 *   ShippingLabel.GraphicImage, and TrackResponse shipment→package→activity
 *   traversal. Its label() gap (tracking URL only, empty contents) is fixed
 *   here via POST /api/labels/{v}/recovery.
 *
 * Field choices:
 * - Single aggregated Package (summed weight, first dimensioned parcel) like
 *   shipbridge-ups, not one entry per parcel: UPS REST examples and the
 *   reference driver both price/ship one Package object, and NumOfPieces is
 *   derived from parcel quantities. Multi-package expansion is unverified
 *   against live keys and intentionally out of scope.
 * - Service default 11 (UPS Standard, the lane-agnostic code from the
 *   shipbridge-ups config) and NegotiatedRates via ShipmentRatingOptions
 *   (verified in UPS-API/api-documentation Shipping.yaml + Rating.yaml and
 *   the UPS Postman "Shipping with Negotiated Rates" example).
 *
 * Extra UPS fields travel via ShipmentDraft::$metadata:
 * service_code, packaging_code, description, cod, cod_funds_code, currency,
 * weight_unit, dimension_unit, label_image_format, request_option,
 * negotiated_rates, pickup_type, account_number/shipper_number, plus
 * shipper_/sender_/receiver_-prefixed address overrides.
 */
final class UpsPayloadFactory
{
    /**
     * Service codes from gabrielbull Entity\Service (domestic + intl).
     *
     * @var array<string, string>
     */
    public const SERVICE_NAMES = [
        '01' => 'UPS Next Day Air',
        '02' => 'UPS 2nd Day Air',
        '03' => 'UPS Ground',
        '07' => 'UPS Worldwide Express',
        '08' => 'UPS Worldwide Expedited',
        '11' => 'UPS Standard',
        '12' => 'UPS 3 Day Select',
        '13' => 'UPS Next Day Air Saver',
        '14' => 'UPS Next Day Air Early',
        '54' => 'UPS Worldwide Express Plus',
        '59' => 'UPS 2nd Day Air A.M.',
        '65' => 'UPS Worldwide Saver',
        '70' => 'UPS Access Point Economy',
        '82' => 'UPS Today Standard',
        '83' => 'UPS Today Dedicated Courier',
        '85' => 'UPS Today Express',
        '86' => 'UPS Today Express Saver',
        '96' => 'UPS Worldwide Express Freight',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * Ship payload: ShipmentRequest envelope for POST …/ship.
     *
     * @return array<string, mixed>
     */
    public function create(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;

        $phone = $draft->destination->phone ?? (isset($meta['phone']) ? (string) $meta['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('UPS requires ShipTo phone (Address::$phone or metadata.phone).');
        }

        $account = (string) (
            $meta['account_number']
            ?? $meta['shipper_number']
            ?? $this->config['account_number']
            ?? $this->config['shipper_number']
            ?? ''
        );

        if ($account === '') {
            throw ShippingException::carrierFailed('UPS requires account_number/shipper_number in config or metadata.');
        }

        $serviceCode = (string) ($meta['service_code'] ?? $this->config['service_code'] ?? '11');
        $packagingCode = (string) ($meta['packaging_code'] ?? $this->config['packaging_code'] ?? '02');
        $weightUnit = strtoupper((string) ($meta['weight_unit'] ?? $this->config['weight_unit'] ?? 'KGS'));
        $dimensionUnit = strtoupper((string) ($meta['dimension_unit'] ?? $this->config['dimension_unit'] ?? 'CM'));
        $labelFormat = strtoupper((string) ($meta['label_image_format'] ?? $this->config['label_image_format'] ?? 'GIF'));
        $description = (string) ($meta['description'] ?? ($draft->reference !== null && $draft->reference !== '' ? $draft->reference : 'Shipment'));

        $shipment = [
            'Description' => $description,
            'Shipper' => $this->party($draft->origin, $meta, 'shipper', $account),
            'ShipTo' => $this->party($draft->destination, $meta, 'receiver'),
            'ShipFrom' => $this->party($draft->origin, $meta, 'sender'),
            'PaymentInformation' => [
                'ShipmentCharge' => [
                    'Type' => '01',
                    'BillShipper' => [
                        'AccountNumber' => $account,
                    ],
                ],
            ],
            'Service' => $this->service($serviceCode),
            'Package' => $this->package($draft->parcels, $meta, $packagingCode, $weightUnit, $dimensionUnit),
        ];

        if ($this->isTruthy($meta['negotiated_rates'] ?? $this->config['negotiated_rates'] ?? false)) {
            $shipment['ShipmentRatingOptions'] = [
                'NegotiatedRatesIndicator' => 'Y',
            ];
        }

        $cod = (float) ($meta['cod'] ?? $meta['cod_amount'] ?? 0);

        if ($cod > 0) {
            $currency = strtoupper((string) ($meta['currency'] ?? $this->config['currency'] ?? 'USD'));

            $shipment['ShipmentServiceOptions'] = [
                'COD' => [
                    'CODFundsCode' => (string) ($meta['cod_funds_code'] ?? '0'),
                    'CODAmount' => [
                        'CurrencyCode' => $currency,
                        'MonetaryValue' => $this->formatNumber($cod),
                    ],
                ],
            ];
        }

        $payload = [
            'ShipmentRequest' => [
                'Request' => [
                    'RequestOption' => (string) ($meta['request_option'] ?? 'nonvalidate'),
                ],
                'Shipment' => $shipment,
                'LabelSpecification' => [
                    'LabelImageFormat' => [
                        'Code' => $labelFormat,
                    ],
                ],
            ],
        ];

        if ($draft->reference !== null && $draft->reference !== '') {
            $payload['ShipmentRequest']['Shipment']['ReferenceNumber'] = [
                'Code' => 'IN',
                'Value' => $draft->reference,
            ];
        }

        return $payload;
    }

    /**
     * Rating payload: RateRequest envelope for POST …/rating/{v}/Shop.
     *
     * Shop (all services) is used: Service is omitted unless the caller pins
     * metadata.service_code, so one call returns comparable RateQuotes.
     *
     * @return array<string, mixed>
     */
    public function rate(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;

        $account = (string) (
            $meta['account_number']
            ?? $meta['shipper_number']
            ?? $this->config['account_number']
            ?? $this->config['shipper_number']
            ?? ''
        );

        if ($account === '') {
            throw ShippingException::carrierFailed('UPS requires account_number/shipper_number in config or metadata.');
        }

        $packagingCode = (string) ($meta['packaging_code'] ?? $this->config['packaging_code'] ?? '02');
        $weightUnit = strtoupper((string) ($meta['weight_unit'] ?? $this->config['weight_unit'] ?? 'KGS'));
        $dimensionUnit = strtoupper((string) ($meta['dimension_unit'] ?? $this->config['dimension_unit'] ?? 'CM'));

        $shipment = [
            'Shipper' => $this->party($draft->origin, $meta, 'shipper', $account),
            'ShipTo' => $this->party($draft->destination, $meta, 'receiver'),
            'ShipFrom' => $this->party($draft->origin, $meta, 'sender'),
            'PaymentDetails' => [
                'ShipmentCharge' => [
                    'Type' => '01',
                    'BillShipper' => [
                        'AccountNumber' => $account,
                    ],
                ],
            ],
            'NumOfPieces' => (string) $this->pieces($draft->parcels),
            'Package' => $this->ratingPackage($draft->parcels, $meta, $packagingCode, $weightUnit, $dimensionUnit),
        ];

        $serviceCode = $meta['service_code'] ?? $this->config['service_code'] ?? null;

        if (is_string($serviceCode) && $serviceCode !== '') {
            $shipment['Service'] = $this->service($serviceCode);
        }

        if ($this->isTruthy($meta['negotiated_rates'] ?? $this->config['negotiated_rates'] ?? false)) {
            $shipment['ShipmentRatingOptions'] = [
                'NegotiatedRatesIndicator' => 'Y',
            ];
        }

        return [
            'RateRequest' => [
                'Request' => [
                    'RequestOption' => 'Shop',
                ],
                'PickupType' => [
                    'Code' => (string) ($meta['pickup_type'] ?? $this->config['pickup_type'] ?? '01'),
                ],
                'Shipment' => $shipment,
            ],
        ];
    }

    /**
     * Label-recovery payload for POST …/labels/{v}/recovery.
     *
     * @return array<string, mixed>
     */
    public function label(string $trackingNumber, string $imageFormat = 'GIF'): array
    {
        $format = strtoupper($imageFormat !== '' ? $imageFormat : 'GIF');

        return [
            'LabelRecoveryRequest' => [
                'Request' => [
                    'RequestOption' => 'Non_Validate',
                ],
                'LabelSpecification' => [
                    'HTTPUserAgent' => 'Mozilla/4.5',
                    'LabelImageFormat' => [
                        'Code' => $format,
                    ],
                ],
                'TrackingNumber' => $trackingNumber,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function service(string $code): array
    {
        $service = ['Code' => $code];

        if (isset(self::SERVICE_NAMES[$code])) {
            $service['Description'] = self::SERVICE_NAMES[$code];
        }

        return $service;
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function package(array $parcels, array $meta, string $packagingCode, string $weightUnit, string $dimensionUnit): array
    {
        $first = $parcels[0] ?? new Parcel(weightKg: 1.0);

        $package = [
            'Packaging' => [
                'Code' => $packagingCode,
            ],
            'PackageWeight' => [
                'UnitOfMeasurement' => [
                    'Code' => $weightUnit,
                ],
                'Weight' => $this->formatNumber($this->weightKg($parcels)),
            ],
        ];

        $dimensions = $this->dimensions($first, $meta, $dimensionUnit);

        if ($dimensions !== null) {
            $package['Dimensions'] = $dimensions;
        }

        $description = $first->description ?? '';

        if ($description === '') {
            $description = (string) ($meta['package_description'] ?? 'Package');
        }

        $package['Description'] = $description;

        return $package;
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function ratingPackage(array $parcels, array $meta, string $packagingCode, string $weightUnit, string $dimensionUnit): array
    {
        $first = $parcels[0] ?? new Parcel(weightKg: 1.0);

        $package = [
            'PackagingType' => [
                'Code' => $packagingCode,
            ],
            'PackageWeight' => [
                'UnitOfMeasurement' => [
                    'Code' => $weightUnit,
                ],
                'Weight' => $this->formatNumber($this->weightKg($parcels)),
            ],
        ];

        $length = (float) ($meta['length'] ?? $first->lengthCm ?? 0);
        $width = (float) ($meta['width'] ?? $first->widthCm ?? 0);
        $height = (float) ($meta['height'] ?? $first->heightCm ?? 0);

        if ($length > 0 && $width > 0 && $height > 0) {
            $package['Dimensions'] = [
                'UnitOfMeasurement' => [
                    'Code' => $dimensionUnit,
                ],
                'Length' => $this->formatNumber($length),
                'Width' => $this->formatNumber($width),
                'Height' => $this->formatNumber($height),
            ];
        }

        return $package;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function party(Address $address, array $meta, string $role, ?string $shipperNumber = null): array
    {
        $prefix = match ($role) {
            'shipper' => 'shipper_',
            'sender' => 'sender_',
            default => 'receiver_',
        };

        $phone = $address->phone
            ?? (isset($meta[$prefix.'phone']) ? (string) $meta[$prefix.'phone'] : null)
            ?? ($role === 'receiver' && isset($meta['phone']) ? (string) $meta['phone'] : null)
            ?? '';

        $lines = array_values(array_filter([
            (string) ($meta[$prefix.'line1'] ?? $address->line1),
            isset($meta[$prefix.'line2']) ? (string) $meta[$prefix.'line2'] : ($address->line2 ?? ''),
        ], static fn (string $line): bool => $line !== ''));

        $party = [
            'Name' => (string) ($meta[$prefix.'name'] ?? $address->name),
            'AttentionName' => (string) ($meta[$prefix.'attention'] ?? $address->name),
            'Phone' => [
                'Number' => $phone,
            ],
            'Address' => [
                'AddressLine' => $lines !== [] ? $lines : [$address->line1],
                'City' => (string) ($meta[$prefix.'city'] ?? $address->city),
                'StateProvinceCode' => (string) ($meta[$prefix.'state'] ?? $address->state ?? ''),
                'PostalCode' => (string) ($meta[$prefix.'postal_code'] ?? $address->postalCode ?? ''),
                'CountryCode' => strtoupper((string) ($meta[$prefix.'country_code'] ?? $address->countryCode)),
            ],
        ];

        if (is_string($shipperNumber) && $shipperNumber !== '') {
            $party['ShipperNumber'] = $shipperNumber;
        }

        $email = $address->email ?? (isset($meta[$prefix.'email']) ? (string) $meta[$prefix.'email'] : null);

        if (is_string($email) && $email !== '') {
            $party['EMailAddress'] = $email;
        }

        return $party;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null
     */
    private function dimensions(Parcel $parcel, ?array $meta, string $unit): ?array
    {
        $length = (float) ($meta['length'] ?? $parcel->lengthCm ?? 0);
        $width = (float) ($meta['width'] ?? $parcel->widthCm ?? 0);
        $height = (float) ($meta['height'] ?? $parcel->heightCm ?? 0);

        if ($length <= 0 || $width <= 0 || $height <= 0) {
            return null;
        }

        return [
            'UnitOfMeasurement' => [
                'Code' => $unit,
            ],
            'Length' => $this->formatNumber($length),
            'Width' => $this->formatNumber($width),
            'Height' => $this->formatNumber($height),
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

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'y', 'yes', 'true', 'x'], true);
        }

        return (bool) $value;
    }
}
