<?php

declare(strict_types=1);

use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\Manager\ShippingManager;

test('manager lists registered and shared carriers', function () {
    $manager = new ShippingManager;
    $manager->share('fake', new FakeDriver);
    $manager->extend('custom', fn () => new FakeDriver);

    expect($manager->registered())->toBe(['custom', 'fake']);
});
