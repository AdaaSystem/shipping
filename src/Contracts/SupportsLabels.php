<?php

declare(strict_types=1);

namespace Adaa\Shipping\Contracts;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\LabelResult;
use Adaa\Shipping\Enums\LabelFormat;

interface SupportsLabels
{
    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult;
}
