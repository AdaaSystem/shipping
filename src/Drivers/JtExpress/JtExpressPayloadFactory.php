<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\JtExpress;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → J&T Egypt addOrder bizContent.
 *
 * Field shape verified against genius-code/jt-express-eg
 * (OrderRequestBuilder + OrderRequest DTO) and mohammedam33n/jt-express-laravel
 * (createOrder base data), structured like shipbridge-jtexpress PayloadFactory.
 *
 * Defaults: deliveryType `04`, payType `PP_PM` (`PP_CASH` when COD > 0),
 * expressType `EZ`, serviceType `01`, goodsType `ITN1`, currency `EGP`,
 * countryCode `EGY`, orderType `2`, operateType `1`.
 *
 * Return and exchange travel through the create payload via
 * metadata.express_type = `DR` (return) / `EX` (exchange) — J&T exposes no
 * separate return/exchange endpoint; shipbridge-jtexpress uses the same
 * expressType switch on addOrder.
 *
 * Extra J&T fields travel via ShipmentDraft::$metadata:
 * prov, city, area, cod, pay_type, express_type, service_type, delivery_type,
 * goods_type, order_type, currency, network, remark/notes, building, floor,
 * apartment/flats, length/width/height, total_quantity, txlogistic_id,
 * invoice_number, items[], plus sender_* overrides (name, phone, prov, city…).
 */
final class JtExpressPayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $bizDigest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(ShipmentDraft $draft): array
    {
        $meta = $draft->metadata;
        $cod = (float) ($meta['cod'] ?? $meta['items_value'] ?? 0);

        return $this->waybill(
            sender: $draft->origin,
            receiver: $draft->destination,
            parcels: $draft->parcels,
            reference: $draft->reference,
            metadata: $meta,
            expressType: (string) ($meta['express_type'] ?? $this->config['express_type'] ?? 'EZ'),
            orderType: (string) ($meta['order_type'] ?? $this->config['order_type'] ?? '2'),
            serviceType: (string) ($meta['service_type'] ?? $this->config['service_type'] ?? '01'),
            itemsValue: $cod,
            remark: (string) ($meta['notes'] ?? $meta['remark'] ?? ''),
        );
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function waybill(
        Address $sender,
        Address $receiver,
        array $parcels,
        ?string $reference,
        array $metadata,
        string $expressType,
        string $orderType,
        string $serviceType,
        float $itemsValue,
        string $remark,
    ): array {
        $phone = $receiver->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($phone === null || $phone === '') {
            throw ShippingException::carrierFailed('J&T requires receiver phone (Address::$phone).');
        }

        $prov = (string) ($metadata['prov'] ?? $metadata['governorate'] ?? $receiver->city);
        $city = (string) ($metadata['city'] ?? $metadata['district'] ?? $metadata['neighborhood'] ?? $receiver->state ?? $receiver->city);
        $area = (string) ($metadata['area'] ?? '0');

        if ($prov === '' || $city === '') {
            throw ShippingException::carrierFailed('J&T requires receiver prov + city (metadata.prov / metadata.city).');
        }

        $weight = 0.0;

        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
        }

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $txlogisticId = (string) ($metadata['txlogistic_id'] ?? ($reference !== null && $reference !== '' ? $reference : 'ADAA'.substr((string) time(), -8).random_int(100, 999)));

        $payType = (string) ($metadata['pay_type'] ?? ($itemsValue > 0
            ? ($this->config['pay_type_cod'] ?? 'PP_CASH')
            : ($this->config['pay_type'] ?? 'PP_PM')));

        $first = $parcels[0] ?? null;

        return [
            'customerCode' => (string) ($this->config['customer_code'] ?? ''),
            'digest' => $this->bizDigest,
            'network' => (string) ($metadata['network'] ?? ''),
            'txlogisticId' => $txlogisticId,
            'orderType' => $orderType,
            'serviceType' => $serviceType,
            'deliveryType' => (string) ($metadata['delivery_type'] ?? $this->config['delivery_type'] ?? '04'),
            'payType' => $payType,
            'expressType' => $expressType,
            'goodsType' => (string) ($metadata['goods_type'] ?? $this->config['goods_type'] ?? 'ITN1'),
            'invoceNumber' => (string) ($metadata['invoice_number'] ?? $reference ?? ''),
            'packingNumber' => (string) ($metadata['packing_number'] ?? ''),
            'batchNumber' => (string) ($metadata['batch_number'] ?? ''),
            'billCode' => (string) ($metadata['bill_code'] ?? ''),
            'sendStartTime' => (string) ($metadata['send_start_time'] ?? date('Y-m-d H:i:s')),
            'sendEndTime' => (string) ($metadata['send_end_time'] ?? date('Y-m-d H:i:s', time() + 86400)),
            'length' => (float) ($metadata['length'] ?? $first?->lengthCm ?? 0),
            'width' => (float) ($metadata['width'] ?? $first?->widthCm ?? 0),
            'height' => (float) ($metadata['height'] ?? $first?->heightCm ?? 0),
            'weight' => $weight,
            'totalQuantity' => (int) ($metadata['total_quantity'] ?? max(1, count($parcels))),
            'itemsValue' => $itemsValue,
            'priceCurrency' => (string) ($metadata['currency'] ?? $this->config['currency'] ?? 'EGP'),
            'operateType' => (int) ($metadata['operate_type'] ?? 1),
            'remark' => $remark,
            'offerFee' => (string) ($metadata['offer_fee'] ?? '0'),
            'sender' => $this->party($sender, $metadata, 'sender'),
            'receiver' => $this->party($receiver, $metadata, 'receiver', $prov, $city, $area),
            'items' => $this->items($parcels, $metadata, $itemsValue),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function party(
        Address $address,
        array $metadata,
        string $role,
        ?string $prov = null,
        ?string $city = null,
        ?string $area = null,
    ): array {
        $prefix = $role.'_';
        $phone = $address->phone
            ?? (isset($metadata[$prefix.'phone']) ? (string) $metadata[$prefix.'phone'] : null)
            ?? (isset($metadata['phone']) && $role === 'receiver' ? (string) $metadata['phone'] : null)
            ?? '';

        return [
            'name' => (string) ($metadata[$prefix.'name'] ?? $address->name),
            'company' => (string) ($metadata[$prefix.'company'] ?? $address->name),
            'postCode' => (string) ($metadata[$prefix.'post_code'] ?? $address->postalCode ?? ''),
            'mailBox' => (string) ($metadata[$prefix.'email'] ?? $address->email ?? ''),
            'mobile' => $phone,
            'phone' => (string) ($metadata[$prefix.'phone2'] ?? $phone),
            'countryCode' => (string) ($metadata[$prefix.'country_code'] ?? $this->config['country_code'] ?? 'EGY'),
            'prov' => $prov ?? (string) ($metadata[$prefix.'prov'] ?? $address->city),
            'city' => $city ?? (string) ($metadata[$prefix.'city'] ?? $address->state ?? $address->city),
            'area' => $area ?? (string) ($metadata[$prefix.'area'] ?? '0'),
            'street' => (string) ($metadata[$prefix.'street'] ?? trim($address->line1.($address->line2 !== null && $address->line2 !== '' ? ', '.$address->line2 : ''))),
            'building' => (string) ($metadata[$prefix.'building'] ?? $metadata['building'] ?? ''),
            'floor' => (string) ($metadata[$prefix.'floor'] ?? $metadata['floor'] ?? ''),
            'flats' => (string) ($metadata[$prefix.'flats'] ?? $metadata['apartment'] ?? ''),
            'longitude' => (string) ($metadata[$prefix.'longitude'] ?? ''),
            'latitude' => (string) ($metadata[$prefix.'latitude'] ?? ''),
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function items(array $parcels, array $metadata, float $itemsValue): array
    {
        if (isset($metadata['items']) && is_array($metadata['items']) && $metadata['items'] !== []) {
            $custom = [];

            foreach ($metadata['items'] as $row) {
                if (is_array($row)) {
                    $custom[] = $row;
                }
            }

            if ($custom !== []) {
                return $custom;
            }
        }

        $perItem = count($parcels) > 0 ? $itemsValue / count($parcels) : $itemsValue;
        $rows = [];

        foreach ($parcels as $parcel) {
            $name = ($parcel->description !== null && $parcel->description !== '')
                ? $parcel->description
                : (string) ($metadata['item_name'] ?? 'Goods');

            $rows[] = [
                'itemType' => (string) ($metadata['goods_type'] ?? 'ITN1'),
                'itemName' => $name,
                'chineseName' => $name,
                'englishName' => $name,
                'number' => max(1, $parcel->quantity),
                'itemValue' => $perItem,
                'priceCurrency' => (string) ($metadata['currency'] ?? 'EGP'),
                'desc' => $name,
                'itemUrl' => '',
            ];
        }

        if ($rows !== []) {
            return $rows;
        }

        return [[
            'itemType' => 'ITN1',
            'itemName' => 'Goods',
            'chineseName' => 'Goods',
            'englishName' => 'Goods',
            'number' => 1,
            'itemValue' => $itemsValue,
            'priceCurrency' => 'EGP',
            'desc' => 'Goods',
            'itemUrl' => '',
        ]];
    }
}
