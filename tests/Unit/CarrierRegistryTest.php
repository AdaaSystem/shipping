<?php

declare(strict_types=1);

use Adaa\Shipping\Registry\CarrierRegistry;

test('registry resolves eligibility by origin country', function () {
    $registry = new CarrierRegistry(require __DIR__.'/../../config/carriers.php');

    expect($registry->eligibleForOrigin('EG'))->toContain('bosta', 'mylerz', 'aramex')
        ->and($registry->eligibleForOrigin('SA'))->toContain('smsa', 'aramex')
        ->and($registry->eligibleForOrigin('SA'))->not->toContain('bosta');
});

test('tracking-only carriers are distinguishable from creation carriers', function () {
    $registry = new CarrierRegistry(require __DIR__.'/../../config/carriers.php');

    expect($registry->get('egyptpost')['type'])->toBe('tracking-only')
        ->and($registry->get('egyptpost')['capabilities']['create'] ?? false)->toBeFalse();
});
