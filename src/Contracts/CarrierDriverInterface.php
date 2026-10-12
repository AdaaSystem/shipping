<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\DTOs\ShipmentResult;

/**
 * Every carrier driver implements creation. All other operations are
 * opt-in capability contracts so no carrier is forced to fake support.
 */
interface CarrierDriverInterface
{
    public function capabilities(): CarrierCapabilities;

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult;
}
