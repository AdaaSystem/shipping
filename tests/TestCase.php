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
}
