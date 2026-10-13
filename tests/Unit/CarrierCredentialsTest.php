<?php

declare(strict_types=1);

test('every carrier declares the credentials its client actually reads', function () {
    /** @var array<string, array<string, mixed>> $carriers */
    $carriers = require __DIR__.'/../../config/carriers.php';

    $expected = [
        'bosta' => ['api_key'],
        'mylerz' => ['username', 'password'],
        'smsa' => ['passkey'],
        'jtexpress' => ['api_account', 'customer_code', 'private_key', 'customer_pwd'],
        'aramex' => ['username', 'password', 'account_number', 'account_pin', 'account_entity', 'account_country_code'],
        'fedex' => ['client_id', 'client_secret'],
        'dhl' => ['username', 'password', 'account_number'],
        'ups' => ['client_id', 'client_secret'],
        'mng' => ['client_id', 'client_secret', 'customer_number', 'password'],
        'hepsijet' => ['username', 'password', 'abbreviation_code'],
        'yurtici' => ['ws_user_name', 'ws_password'],
        'aras' => ['username', 'password', 'customer_code'],
        'surat' => ['kullanici_adi', 'sifre'],
        'ptt' => ['musteri_id', 'sifre'],
        'egyptpost' => [],
        'fake' => [],
    ];

    expect(array_keys($carriers))->toMatchArray(array_keys($expected));

    foreach ($expected as $code => $keys) {
        expect($carriers[$code]['required_credentials'] ?? null)
            ->toBe($keys, "required_credentials mismatch for [{$code}]");
    }
});
