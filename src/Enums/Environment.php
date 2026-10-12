<?php

declare(strict_types=1);

namespace Adaa\Shipping\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
