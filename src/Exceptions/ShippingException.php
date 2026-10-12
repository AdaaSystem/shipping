<?php

declare(strict_types=1);

namespace Adaa\Shipping\Exceptions;

use RuntimeException;

class ShippingException extends RuntimeException
{
    public static function unknownCarrier(string $code): self
    {
        return new self("Unknown shipping carrier [{$code}].");
    }

    public static function unsupportedOperation(string $carrier, string $operation): self
    {
        return new self("Carrier [{$carrier}] does not support [{$operation}].");
    }

    public static function carrierFailed(string $message, int $status = 0): self
    {
        return new self($message, $status);
    }

    public static function shipmentNotFound(string $reference): self
    {
        return new self("Shipment not found [{$reference}].", 404);
    }
}
