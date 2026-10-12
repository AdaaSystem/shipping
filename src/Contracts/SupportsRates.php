<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\RateQuote;
use Adaa\Shipping\DTOs\ShipmentDraft;

interface SupportsRates
{
    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array;
}
