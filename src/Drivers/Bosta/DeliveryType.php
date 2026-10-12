<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Bosta;

/**
 * Bosta delivery type codes (Bosta Business API).
 */
final class DeliveryType
{
    public const SEND = 10;

    public const CASH_COLLECTION = 15;

    public const RTO = 20;

    public const CRP = 25;

    public const EXCHANGE = 30;

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::SEND => 'Package Delivery (Forward)',
            self::CASH_COLLECTION => 'Cash Collection',
            self::RTO => 'Return to Origin',
            self::CRP => 'Customer Return Pickup',
            self::EXCHANGE => 'Exchange',
        ];
    }
}
