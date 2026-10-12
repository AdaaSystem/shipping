<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\TrackingResult;

interface SupportsTracking
{
    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult;
}
