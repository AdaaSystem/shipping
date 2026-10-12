<?php

declare(strict_types=1);

namespace Adaa\Shipping\Enums;

enum ConnectionMode: string
{
    case Platform = 'platform';
    case Merchant = 'merchant';
}
