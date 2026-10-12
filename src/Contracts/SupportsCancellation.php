<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

use Adaa\Shipping\Connection\ShippingConnectionContext;

interface SupportsCancellation
{
    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool;
}
