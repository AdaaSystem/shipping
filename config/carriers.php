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
        'capabilities' => [
            'create' => true, 'label' => true, 'track' => true,
            'return' => true, 'exchange' => true, 'cod' => true,
        ],
    ],
    'aramex' => [
        'name' => 'Aramex',
        'region' => 'MENA / Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
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
        'capabilities' => [
            'rates' => true, 'create' => true, 'track' => true,
        ],
    ],
    'ups' => [
        'name' => 'UPS',
        'region' => 'Global',
        'type' => 'direct',
        'origin_countries' => ['*'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true, 'track' => true,
            'cancel' => true, 'cod' => true,
        ],
    ],
    'egyptpost' => [
        'name' => 'Egypt Post',
        'region' => 'Egypt',
        'type' => 'tracking-only',
        'origin_countries' => ['EG'],
        'capabilities' => ['track' => true],
        'note' => 'No public creation API; shipment creation needs a contracted partner gateway.',
    ],
    'fake' => [
        'name' => 'Fake (tests)',
        'region' => 'Tests',
        'type' => 'fake',
        'origin_countries' => ['*'],
        'capabilities' => [
            'rates' => true, 'create' => true, 'label' => true,
            'track' => true, 'cancel' => true, 'cod' => true, 'webhooks' => true,
        ],
    ],
];
