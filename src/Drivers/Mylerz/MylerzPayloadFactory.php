<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Mylerz;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Mylerz AddOrders package objects
 * (official WooCommerce plugin field names).
 *
 * Return and exchange travel through the create payload via
 * metadata.service_category = RETURN|EXCHANGE — Mylerz has no separate
 * return/exchange endpoint, only AddOrders with a category.
 */
final class MylerzPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function create(ShipmentDraft $draft): array
    {
        $category = (string) ($draft->metadata['service_category'] ?? $this->config['service_category'] ?? 'DELIVERY');

        return [$this->package($draft->destination, $draft->parcels, $draft->reference, $draft->metadata, $category)];
    }

    /**
     * @param  list<Parcel>|null  $parcels
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function package(Address $customer, ?array $parcels, ?string $reference, array $meta, string $defaultCategory): array
    {
        $phone = $customer->phone ?? (isset($meta['phone']) ? (string) $meta['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('Mylerz requires customer Mobile_No (Address::$phone).');
        }

        $neighborhood = (string) ($meta['neighborhood'] ?? $meta['zone'] ?? $customer->state ?? $customer->city);

        if ($neighborhood === '') {
            throw ShippingException::carrierFailed('Mylerz requires Neighborhood (metadata.neighborhood).');
        }

        $cod = (float) ($meta['cod'] ?? 0);
        $paymentType = (string) ($meta['payment_type'] ?? ($cod > 0 ? 'COD' : 'PP'));

        $pieces = [];

        foreach ($parcels ?? [new Parcel(weightKg: 1.0)] as $i => $parcel) {
            $pieces[] = [
                'PieceNo' => $i + 1,
                'Weight' => $parcel->weightKg,
                'Special_Notes' => (string) ($meta['notes'] ?? ''),
            ];
        }

        return [
            'WarehouseName' => (string) ($meta['warehouse'] ?? $this->config['warehouse_name'] ?? 'Main'),
            'PickupDueDate' => (string) ($meta['pickup_due_date'] ?? date('Y-m-d H:i:s')),
            'Package_Serial' => (int) ($meta['package_serial'] ?? 1),
            'Reference' => (string) ($meta['reference'] ?? ($reference !== null && $reference !== '' ? '#'.$reference : '#SHIP')),
            'Description' => (string) ($meta['description'] ?? (($parcels[0] ?? null)?->description ?? 'Goods')),
            'Service_Type' => (string) ($meta['service_type'] ?? $this->config['service_type'] ?? 'DTD'),
            'Service' => (string) ($meta['service'] ?? $this->config['service'] ?? 'ND'),
            'Service_Category' => (string) ($meta['service_category'] ?? $this->config['service_category'] ?? $defaultCategory),
            'Payment_Type' => $paymentType,
            'COD_Value' => $cod,
            'Pieces' => $pieces,
            'Customer_Name' => $customer->name,
            'Mobile_No' => $phone,
            'Street' => trim($customer->line1.($customer->line2 ? ', '.$customer->line2 : '').', '.$customer->city),
            'Country' => (string) ($meta['country'] ?? $this->config['country'] ?? 'Egypt'),
            'Neighborhood' => $neighborhood,
            'Address_Category' => (string) ($meta['address_category'] ?? $this->config['address_category'] ?? 'H'),
            'Currency' => (string) ($meta['currency'] ?? $this->config['currency'] ?? 'EGP'),
        ];
    }
}
