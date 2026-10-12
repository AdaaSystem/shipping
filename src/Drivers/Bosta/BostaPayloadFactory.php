<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Bosta;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → Bosta delivery JSON payloads.
 *
 * Extra Bosta fields travel via ShipmentDraft::$metadata:
 * city_code, zone, district, district_id, building_number, floor, apartment,
 * first_name, last_name, cod, type, notes, webhook_url, allow_to_open_package, specs.
 */
final class BostaPayloadFactory
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
        $meta = $draft->metadata;
        $type = (int) ($meta['type'] ?? $this->config['default_type'] ?? DeliveryType::SEND);

        $payload = [
            'type' => $type,
            'cod' => $this->cod($meta),
            'receiver' => $this->receiver($draft->destination, $meta),
            'dropOffAddress' => $this->address($draft->destination, $meta, 'dropoff'),
            'pickupAddress' => $this->address($draft->origin, $meta, 'pickup'),
        ];

        if ($draft->reference !== null && $draft->reference !== '') {
            $payload['businessReference'] = $draft->reference;
        }

        $notes = $meta['notes'] ?? null;

        if (is_string($notes) && $notes !== '') {
            $payload['notes'] = $notes;
        }

        $webhook = $meta['webhook_url'] ?? $this->config['webhook_url'] ?? null;

        if (is_string($webhook) && $webhook !== '') {
            $payload['webhookUrl'] = $webhook;
        }

        $specs = $this->specs($draft->parcels, $meta);

        if ($specs !== []) {
            $payload['specs'] = $specs;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function receiver(Address $address, array $meta): array
    {
        [$first, $last] = $this->splitName($address->name, $meta);
        $phone = $address->phone ?? (isset($meta['phone']) ? (string) $meta['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed(
                'Bosta requires receiver phone. Set Address::$phone or metadata.phone.'
            );
        }

        $receiver = [
            'firstName' => $first,
            'lastName' => $last,
            'phone' => $phone,
        ];

        $email = $address->email ?? (isset($meta['email']) ? (string) $meta['email'] : null);

        if (is_string($email) && $email !== '') {
            $receiver['email'] = $email;
        }

        return $receiver;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function address(Address $address, array $meta, string $prefix): array
    {
        $cityCode = $meta["{$prefix}_city_code"]
            ?? $meta['city_code']
            ?? $this->guessCityCode($address);

        $zone = $meta["{$prefix}_zone"]
            ?? $meta['zone']
            ?? $address->state
            ?? $address->city;

        $payload = [
            'city' => $cityCode,
            'zone' => (string) $zone,
            'firstLine' => $address->line1,
        ];

        if ($address->line2 !== null && $address->line2 !== '') {
            $payload['secondLine'] = $address->line2;
        }

        $districtId = $meta["{$prefix}_district_id"] ?? $meta['district_id'] ?? null;

        if (is_string($districtId) && $districtId !== '') {
            $payload['districtId'] = $districtId;
        }

        $district = $meta["{$prefix}_district"] ?? $meta['district'] ?? null;

        if (is_string($district) && $district !== '') {
            $payload['district'] = $district;
        }

        foreach (['building_number' => 'buildingNumber', 'floor' => 'floor', 'apartment' => 'apartment'] as $metaKey => $apiKey) {
            $value = $meta["{$prefix}_{$metaKey}"] ?? $meta[$metaKey] ?? null;

            if ($value !== null && $value !== '') {
                $payload[$apiKey] = is_numeric($value) ? (int) $value : (string) $value;
            }
        }

        return $payload;
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function specs(array $parcels, array $meta): array
    {
        if (isset($meta['specs']) && is_array($meta['specs'])) {
            return $meta['specs'];
        }

        if ($parcels === []) {
            return [];
        }

        $parcel = $parcels[0];
        $packageDetails = [
            'itemsCount' => max(1, $parcel->quantity),
            'description' => $parcel->description,
        ];

        if ($parcel->weightKg > 0) {
            $packageDetails['weight'] = $parcel->weightKg;
        }

        $size = [];

        if ($parcel->lengthCm !== null) {
            $size['length'] = $parcel->lengthCm;
        }

        if ($parcel->widthCm !== null) {
            $size['width'] = $parcel->widthCm;
        }

        if ($parcel->heightCm !== null) {
            $size['height'] = $parcel->heightCm;
        }

        $specs = [
            'packageDetails' => $packageDetails,
        ];

        if ($size !== []) {
            $specs['packageType'] = 'Parcel';
            $specs['size'] = $size;
        }

        if (isset($meta['allow_to_open_package'])) {
            $specs['packageDetails']['allowToOpenPackage'] = (bool) $meta['allow_to_open_package'];
        }

        return $specs;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name, array $meta): array
    {
        if (isset($meta['first_name']) || isset($meta['last_name'])) {
            return [
                (string) ($meta['first_name'] ?? 'Customer'),
                (string) ($meta['last_name'] ?? '.'),
            ];
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '.';

        return [$first, $last];
    }

    private function guessCityCode(Address $address): string
    {
        $city = strtolower(trim($address->city));
        $map = [
            'cairo' => 'EG-01',
            'القاهرة' => 'EG-01',
            'giza' => 'EG-02',
            'الجيزة' => 'EG-02',
            'alexandria' => 'EG-03',
            'alex' => 'EG-03',
            'الإسكندرية' => 'EG-03',
            'اسكندرية' => 'EG-03',
        ];

        if (isset($map[$city])) {
            return $map[$city];
        }

        if (preg_match('/^EG-\d{2}$/i', $address->city) === 1) {
            return strtoupper($address->city);
        }

        return (string) ($this->config['default_city_code'] ?? 'EG-01');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function cod(array $meta): float|int
    {
        $cod = $meta['cod'] ?? 0;

        return is_numeric($cod) ? $cod + 0 : 0;
    }
}
