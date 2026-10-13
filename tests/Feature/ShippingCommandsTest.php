<?php

declare(strict_types=1);

test('drivers command lists registered carriers', function () {
    $this->artisan('shipping:drivers')
        ->assertSuccessful()
        ->expectsOutputToContain('fake');
});

test('diagnose command reports registry metadata', function () {
    $this->artisan('shipping:diagnose', ['carrier' => 'bosta'])
        ->assertSuccessful()
        ->expectsOutputToContain('Bosta')
        ->expectsOutputToContain('api_key');
});

test('diagnose command rejects unknown carriers', function () {
    $this->artisan('shipping:diagnose', ['carrier' => 'nope'])
        ->assertFailed();
});
