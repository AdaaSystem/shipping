<?php

declare(strict_types=1);

use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Support\StatusNormalizer;

test('normalizes carrier statuses case-insensitively', function () {
    $normalizer = new StatusNormalizer(['OFD' => 'out_for_delivery']);

    expect($normalizer->normalize('OFD'))->toBe(ShipmentStatus::OutForDelivery)
        ->and($normalizer->normalize('delivered'))->toBe(ShipmentStatus::Delivered)
        ->and($normalizer->normalize('Out For Delivery'))->toBe(ShipmentStatus::OutForDelivery);
});

test('unknown statuses fall back to exception instead of throwing', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('SOME_WEIRD_STATUS'))->toBe(ShipmentStatus::Exception);
});
