<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Static carrier registry
|--------------------------------------------------------------------------
| Static metadata only. A carrier listed here is NOT proof of shipment
| support: tracking-only carriers must never be offered for creation.
| Capabilities below mirror each driver's declared CarrierCapabilities.
*/

return [
    'bosta' => [
        'name' => 'Bosta',
        'region' => 'Egypt',
        'type' => 'direct',
        'origin_countries' => ['EG'],
        'required_credentials' => ['api_key'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'mylerz' => [
        'name' => 'Mylerz',
        'region' => 'Egypt / MENA',
        'type' => 'direct',
        'origin_countries' => ['EG'],
        'required_credentials' => ['username', 'password'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'smsa' => [
        'name' => 'SMSA Express',
        'region' => 'KSA / GCC',
        'type' => 'direct',
        'origin_countries' => ['SA', 'AE', 'KW', 'BH', 'QA', 'OM'],
        'required_credentials' => ['passkey'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'jtexpress' => [
        'name' => 'J&T Express',
        'region' => 'Egypt',
        'type' => 'direct',
        'origin_countries' => ['EG'],
        'required_credentials' => ['api_account', 'customer_code', 'private_key', 'customer_pwd'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'aramex' => [
        'name' => 'Aramex',
        'region' => 'MENA / Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
        'required_credentials' => ['username', 'password', 'account_number', 'account_pin', 'account_entity', 'account_country_code'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'fedex' => [
        'name' => 'FedEx',
        'region' => 'Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
        'required_credentials' => ['client_id', 'client_secret'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'dhl' => [
        'name' => 'DHL Express',
        'region' => 'Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
        'required_credentials' => ['username', 'password', 'account_number'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'track' => true,
        ],
    ],
    'ups' => [
        'name' => 'UPS',
        'region' => 'Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
        'required_credentials' => ['client_id', 'client_secret'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true, 'track' => true,
            'cancel' => true, 'cod' => true,
        ],
    ],
    'mng' => [
        'name' => 'MNG Kargo',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['client_id', 'client_secret', 'customer_number', 'password'],
        'capabilities' => [
            'create' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'hepsijet' => [
        'name' => 'HepsiJet',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['username', 'password', 'abbreviation_code'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'yurtici' => [
        'name' => 'Yurtiçi Kargo',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['ws_user_name', 'ws_password'],
        'capabilities' => [
            'create' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'aras' => [
        'name' => 'Aras Kargo',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['username', 'password', 'customer_code'],
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'surat' => [
        'name' => 'Sürat Kargo',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['kullanici_adi', 'sifre'],
        'capabilities' => [
            'create' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'ptt' => [
        'name' => 'PTT Kargo',
        'region' => 'Turkey',
        'type' => 'direct',
        'origin_countries' => ['TR'],
        'required_credentials' => ['musteri_id', 'sifre'],
        'capabilities' => [
            'create' => true, 'track' => true,
            'cancel' => true, 'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'egyptpost' => [
        'name' => 'Egypt Post',
        'region' => 'Egypt',
        'type' => 'tracking-only',
        'origin_countries' => ['EG'],
        'required_credentials' => [],
        'capabilities' => ['track' => true],
        'note' => 'No public creation API; shipment creation needs a contracted partner gateway.',
    ],
    'fake' => [
        'name' => 'Fake (tests)',
        'region' => 'Tests',
        'type' => 'fake',
        'origin_countries' => ['*'],
        'required_credentials' => [],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true,
            'track' => true, 'cancel' => true, 'cod' => true, 'webhooks' => true,
        ],
    ],
];
