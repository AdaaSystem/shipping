<?php

declare(strict_types=1);

namespace Adaa\Shipping\Enums;

enum LabelFormat: string
{
    case Pdf = 'pdf';
    case Zpl = 'zpl';
    case Png = 'png';
}
