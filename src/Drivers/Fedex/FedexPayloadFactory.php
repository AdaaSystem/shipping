<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Fedex;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → FedEx Ship + Rate REST JSON payloads.
 *
 * Primary model: mohamedhekal/shipbridge-fedex Support/PayloadFactory (same
 * ShipBridge DTO DNA as this package: shipper/recipients + shipDatestamp +
 * serviceType/packagingType/pickupType + labelSpecification + package line
 * items with KG weight + dims, COD via shipmentSpecialServices). Endpoint and
 * field shapes cross-checked against WhatArmy/FedexRest CreateShipment +
 * CreateRatesRequest (labelResponseOptions LABEL vs URL_ONLY,
 * shippingChargesPayment, rateRequestControlParameters, carrierCodes).
 *
 * Reference choice: shipbridge-fedex for the payload skeleton (it already
 * speaks ShipBridge DTOs), WhatArmy for authoritative FedEx field names and
 * the rates endpoint (/rate/v1/rates/quotes with accountNumber +
 * rateRequestControlParameters + requestedShipment). ShipStream's
 * Saloon DTOs were inspected but not adopted (Saloon dependency).
 *
 * Extra FedEx fields travel via ShipmentDraft::$metadata:
 * service_type, packaging_type, pickup_type, payment_type, label_image_type,
 * label_stock_type, label_response_options (defaults to LABEL so encodedLabel
 * bytes are preserved), weight_units, dimension_units, currency, cod,
 * cod_collection_type, ship_datestamp, shipment_special_services (raw FedEx
 * block override), rate_request_types, carrier_codes, return_transit_times,
 * shipper_/recipient_ address overrides (street, street2, city, state,
 * postal_code, country_code, phone, name, company, email).
 *
 * Return/exchange travel through the same ship endpoint (FedEx has no
 * dedicated return API in this driver; Create Tag is out of scope): the
 * parties are swapped so the customer becomes the shipper.
 */
final class FedexPayloadFactory
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
        return $this->shipment($draft->origin, $draft->destination, $draft->parcels, $draft->metadata);
    }

    /**
     * Return shipment: customer ships back to the warehouse.
     *
     * @return array<string, mixed>
     */
    public function createReturn(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $meta['notes'] = (string) ($meta['return_reason'] ?? $meta['notes'] ?? 'Return shipment');

        return $this->shipment($draft->destination, $draft->origin, $draft->parcels, $meta);
    }

    /**
     * Exchange shipment: same swap as return; the outbound leg is re-created
     * by the caller via create().
     *
     * @return array<string, mixed>
     */
    public function createExchange(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $meta['notes'] = (string) ($meta['exchange_reason'] ?? $meta['notes'] ?? 'Exchange shipment');

        return $this->shipment($draft->destination, $draft->origin, $draft->parcels, $meta);
    }

    /**
     * Rate-quote payload (POST /rate/v1/rates/quotes).
     *
     * serviceType is omitted unless explicitly requested so FedEx returns all
     * available services (one RateQuote per rateReplyDetail).
     *
     * @return array<string, mixed>
     */
    public function rate(ShipmentDraft $draft): array
    {
        $this->requireAccountNumber();

        $meta = $draft->metadata;

        $requestedShipment = [
            'shipper' => $this->party($draft->origin, $meta, 'shipper'),
            'recipient' => $this->party($draft->destination, $meta, 'recipient'),
            'pickupType' => (string) ($meta['pickup_type'] ?? $this->config['pickup_type'] ?? 'DROPOFF_AT_FEDEX_LOCATION'),
            'requestedPackageLineItems' => $this->packageLineItems($draft->parcels, $meta),
        ];

        $serviceType = (string) ($meta['service_type'] ?? $this->config['service_type'] ?? '');

        if ($serviceType !== '') {
            $requestedShipment['serviceType'] = $serviceType;
        }

        $packagingType = (string) ($meta['packaging_type'] ?? $this->config['packaging_type'] ?? 'YOUR_PACKAGING');

        if ($packagingType !== '') {
            $requestedShipment['packagingType'] = $packagingType;
        }

        if (isset($meta['ship_datestamp']) && is_string($meta['ship_datestamp']) && $meta['ship_datestamp'] !== '') {
            $requestedShipment['shipDateStamp'] = $meta['ship_datestamp'];
        }

        $rateRequestTypes = $meta['rate_request_types'] ?? null;

        if (is_array($rateRequestTypes) && $rateRequestTypes !== []) {
            $requestedShipment['rateRequestType'] = array_values($rateRequestTypes);
        }

        $specialServices = $this->specialServices($meta);

        if ($specialServices !== null) {
            $requestedShipment['shipmentSpecialServices'] = $specialServices;
        }

        $payload = [
            'accountNumber' => ['value' => (string) ($this->config['account_number'] ?? '')],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => (bool) ($meta['return_transit_times'] ?? false),
                'servicesNeededOnRateFailure' => (bool) ($meta['services_needed_on_rate_failure'] ?? false),
            ],
            'requestedShipment' => $requestedShipment,
        ];

        $carrierCodes = $meta['carrier_codes'] ?? $this->config['carrier_codes'] ?? null;

        if (is_array($carrierCodes) && $carrierCodes !== []) {
            $payload['carrierCodes'] = array_values($carrierCodes);
        }

        return $payload;
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function shipment(Address $shipper, Address $recipient, array $parcels, array $metadata): array
    {
        $this->requireAccountNumber();
        $this->requirePhone($recipient, $metadata);

        $requestedShipment = [
            'shipper' => $this->party($shipper, $metadata, 'shipper'),
            'recipients' => [$this->party($recipient, $metadata, 'recipient')],
            'shipDatestamp' => (string) ($metadata['ship_datestamp'] ?? date('Y-m-d')),
            'serviceType' => (string) ($metadata['service_type'] ?? $this->config['service_type'] ?? 'INTERNATIONAL_PRIORITY'),
            'packagingType' => (string) ($metadata['packaging_type'] ?? $this->config['packaging_type'] ?? 'YOUR_PACKAGING'),
            'pickupType' => (string) ($metadata['pickup_type'] ?? $this->config['pickup_type'] ?? 'DROPOFF_AT_FEDEX_LOCATION'),
            'blockInsightVisibility' => (bool) ($metadata['block_insight_visibility'] ?? false),
            'shippingChargesPayment' => [
                'paymentType' => (string) ($metadata['payment_type'] ?? $this->config['payment_type'] ?? 'SENDER'),
            ],
            'labelSpecification' => [
                'imageType' => (string) ($metadata['label_image_type'] ?? $this->config['label_image_type'] ?? 'PDF'),
                'labelStockType' => (string) ($metadata['label_stock_type'] ?? $this->config['label_stock_type'] ?? 'PAPER_85X11_TOP_HALF_LABEL'),
            ],
            'requestedPackageLineItems' => $this->packageLineItems($parcels, $metadata),
        ];

        $specialServices = $this->specialServices($metadata);

        if ($specialServices !== null) {
            $requestedShipment['shipmentSpecialServices'] = $specialServices;
        }

        return [
            'labelResponseOptions' => (string) ($metadata['label_response_options'] ?? 'LABEL'),
            'requestedShipment' => $requestedShipment,
            'accountNumber' => ['value' => (string) ($this->config['account_number'] ?? '')],
        ];
    }

    private function requireAccountNumber(): void
    {
        $account = $this->config['account_number'] ?? null;

        if (! is_string($account) || $account === '') {
            throw ShippingException::carrierFailed('FedEx requires account_number (FEDEX_ACCOUNT_NUMBER).');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function requirePhone(Address $recipient, array $metadata): void
    {
        $phone = $recipient->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('FedEx requires recipient phone (Address::$phone).');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function party(Address $address, array $metadata, string $role): array
    {
        $prefix = $role.'_';

        $phone = $address->phone
            ?? (isset($metadata[$prefix.'phone']) ? (string) $metadata[$prefix.'phone'] : null)
            ?? (isset($metadata['phone']) && $role === 'recipient' ? (string) $metadata['phone'] : null)
            ?? '';

        $line2 = isset($metadata[$prefix.'street2']) ? (string) $metadata[$prefix.'street2'] : ($address->line2 ?? '');
        $streetLines = [];

        foreach ([(string) ($metadata[$prefix.'street'] ?? $address->line1), $line2] as $line) {
            if ($line !== '') {
                $streetLines[] = $line;
            }
        }

        $party = [
            'contact' => array_filter([
                'personName' => (string) ($metadata[$prefix.'name'] ?? $address->name),
                'companyName' => (string) ($metadata[$prefix.'company'] ?? $address->name),
                'phoneNumber' => $phone,
                'emailAddress' => $address->email ?? (isset($metadata[$prefix.'email']) ? (string) $metadata[$prefix.'email'] : null),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'address' => array_filter([
                'streetLines' => $streetLines !== [] ? $streetLines : [(string) ($metadata[$prefix.'street'] ?? $address->line1)],
                'city' => (string) ($metadata[$prefix.'city'] ?? $address->city),
                'stateOrProvinceCode' => isset($metadata[$prefix.'state']) ? (string) $metadata[$prefix.'state'] : $address->state,
                'postalCode' => isset($metadata[$prefix.'postal_code']) ? (string) $metadata[$prefix.'postal_code'] : $address->postalCode,
                'countryCode' => (string) ($metadata[$prefix.'country_code'] ?? $address->countryCode),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        return $party;
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function packageLineItems(array $parcels, array $metadata): array
    {
        $units = (string) ($metadata['weight_units'] ?? $this->config['weight_units'] ?? 'KG');
        $rows = [];

        foreach ($parcels as $parcel) {
            $weight = $parcel->weightKg > 0 ? $parcel->weightKg : 1.0;
            $item = [
                'weight' => [
                    'units' => $units,
                    'value' => round($weight, 3),
                ],
            ];

            if ($parcel->lengthCm !== null && $parcel->widthCm !== null && $parcel->heightCm !== null) {
                $item['dimensions'] = [
                    'length' => (int) round($parcel->lengthCm),
                    'width' => (int) round($parcel->widthCm),
                    'height' => (int) round($parcel->heightCm),
                    'units' => (string) ($metadata['dimension_units'] ?? 'CM'),
                ];
            }

            $rows[] = $item;
        }

        if ($rows !== []) {
            return $rows;
        }

        return [[
            'weight' => [
                'units' => $units,
                'value' => 1.0,
            ],
        ]];
    }

    /**
     * COD is the only verifiable special service: metadata.cod > 0 adds
     * specialServiceTypes [COD] + codCollectionDetail, matching the
     * shipbridge-fedex contract. A raw metadata.shipment_special_services
     * block overrides it when callers need other FedEx services.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function specialServices(array $metadata): ?array
    {
        if (isset($metadata['shipment_special_services']) && is_array($metadata['shipment_special_services'])) {
            /** @var array<string, mixed> $services */
            $services = $metadata['shipment_special_services'];

            return $services;
        }

        $cod = (float) ($metadata['cod'] ?? $metadata['cod_amount'] ?? 0);

        if ($cod <= 0) {
            return null;
        }

        return [
            'specialServiceTypes' => ['COD'],
            'codCollectionDetail' => [
                'codCollectionType' => (string) ($metadata['cod_collection_type'] ?? 'ANY'),
                'codCollectionAmount' => [
                    'amount' => $cod,
                    'currency' => (string) ($metadata['currency'] ?? $this->config['currency'] ?? 'USD'),
                ],
            ],
        ];
    }
}
