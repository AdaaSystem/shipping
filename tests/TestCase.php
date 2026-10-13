<?php

declare(strict_types=1);

namespace Adaa\Shipping\Tests;

use Adaa\Shipping\Laravel\GlobalShippingServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [GlobalShippingServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Routes register at boot, so middleware must be set here —
        // a beforeEach hook would run too late.
        $app['config']->set('shipping.webhook.middleware', ['api']);
    }
}
