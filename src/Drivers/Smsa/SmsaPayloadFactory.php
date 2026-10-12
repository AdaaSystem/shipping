<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Smsa;

use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * Maps ShipmentDraft → SMSA SECOM `addShip` SOAP parameters.
 *
 * Field order follows the live WSDL sequence for `addShip`. Auth (`passKey`)
 * comes from per-connection config only — never from global env.
 *
 * Return/exchange travel through the same `addShip` call via
 * `metadata.ship_type` (`DLV` default, `RET` for returns), mirroring the
 * reference shipbridge-smsa driver which has no dedicated return endpoint
 * (only `stoShipment` for ship-to-origin, unused here).
 *
 * Extra SECOM fields travel via ShipmentDraft::$metadata: ref_no, sent_date,
 * id_no, country, city, zip, po_box, phone, tel1, tel2, addr1, addr2, ship_type,
 * pcs, email, carr_value, carr_curr, cod, cod_amt, cust_val, cust_curr,
 * insr_amt, insr_curr, item_desc, shipper_name, shipper_contact,
 * shipper_addr1, shipper_addr2, shipper_city, shipper_phone, shipper_country,
 * pref_delv_date, gps_points.
 */
final class SmsaPayloadFactory
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

        return $this->addShipParams(
            consignee: $draft->destination,
            shipper: $draft->origin,
            parcels: $draft->parcels,
            reference: $draft->reference,
            metadata: $metadata,
            shipType: (string) ($metadata['ship_type'] ?? $metadata['shipType'] ?? $this->config['ship_type'] ?? 'DLV'),
        );
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function addShipParams(
        Address $consignee,
        Address $shipper,
        array $parcels,
        ?string $reference,
        array $metadata,
        string $shipType,
    ): array {
        $mobile = $consignee->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);

        if ($mobile === null || $mobile === '') {
            throw ShippingException::carrierFailed(
                'SMSA requires consignee mobile. Set Address::$phone or metadata.phone.'
            );
        }

        $city = (string) ($metadata['city'] ?? $consignee->city);

        if ($city === '') {
            throw ShippingException::carrierFailed('SMSA requires consignee city.');
        }

        $weight = 0.0;

        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
        }

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $cod = $metadata['cod'] ?? $metadata['cod_amt'] ?? 0;
        $codAmount = is_numeric($cod) ? (float) $cod : 0.0;
        $description = (string) ($metadata['item_desc'] ?? ((isset($parcels[0]) && is_string($parcels[0]->description)) ? $parcels[0]->description : ''));

        if ($description === '') {
            $description = 'Goods';
        }

        $passKey = (string) ($this->config['passkey'] ?? $this->config['pass_key'] ?? $this->config['passKey'] ?? $this->config['token'] ?? '');

        return [
            'passKey' => $passKey,
            'refNo' => (string) ($metadata['ref_no'] ?? $reference ?? ('ADA'.time())),
            'sentDate' => (string) ($metadata['sent_date'] ?? date('Y-m-d')),
            'idNo' => (string) ($metadata['id_no'] ?? ''),
            'cName' => $consignee->name,
            'cntry' => strtoupper((string) ($metadata['country'] ?? ($consignee->countryCode !== '' ? $consignee->countryCode : 'SA'))),
            'cCity' => strtoupper($city),
            'cZip' => (string) ($metadata['zip'] ?? $consignee->postalCode ?? ''),
            'cPOBox' => (string) ($metadata['po_box'] ?? ''),
            'cMobile' => $mobile,
            'cTel1' => (string) ($metadata['tel1'] ?? $mobile),
            'cTel2' => (string) ($metadata['tel2'] ?? ''),
            'cAddr1' => (string) ($metadata['addr1'] ?? $consignee->line1),
            'cAddr2' => (string) ($metadata['addr2'] ?? $consignee->line2 ?? ''),
            'shipType' => $shipType !== '' ? $shipType : 'DLV',
            'PCs' => (int) ($metadata['pcs'] ?? max(1, count($parcels))),
            'cEmail' => (string) ($metadata['email'] ?? $consignee->email ?? ''),
            'carrValue' => (string) ($metadata['carr_value'] ?? ''),
            'carrCurr' => (string) ($metadata['carr_curr'] ?? 'SAR'),
            'codAmt' => (string) $codAmount,
            'weight' => (string) $weight,
            'custVal' => (string) ($metadata['cust_val'] ?? ''),
            'custCurr' => (string) ($metadata['cust_curr'] ?? 'SAR'),
            'insrAmt' => (string) ($metadata['insr_amt'] ?? ''),
            'insrCurr' => (string) ($metadata['insr_curr'] ?? 'SAR'),
            'itemDesc' => $description,
            'sName' => (string) ($metadata['shipper_name'] ?? $shipper->name),
            'sContact' => (string) ($metadata['shipper_contact'] ?? $shipper->name),
            'sAddr1' => (string) ($metadata['shipper_addr1'] ?? $shipper->line1),
            'sAddr2' => (string) ($metadata['shipper_addr2'] ?? $shipper->line2 ?? ''),
            'sCity' => strtoupper((string) ($metadata['shipper_city'] ?? $shipper->city)),
            'sPhone' => (string) ($metadata['shipper_phone'] ?? $shipper->phone ?? ''),
            'sCntry' => strtoupper((string) ($metadata['shipper_country'] ?? ($shipper->countryCode !== '' ? $shipper->countryCode : 'SA'))),
            'prefDelvDate' => (string) ($metadata['pref_delv_date'] ?? ''),
            'gpsPoints' => (string) ($metadata['gps_points'] ?? ''),
        ];
    }
}
