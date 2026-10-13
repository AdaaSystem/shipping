<?php

declare(strict_types=1);

namespace Adaa\Shipping\Manager;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Exceptions\ShippingException;
use Closure;

/**
 * Resolves a driver for one connection at a time. Drivers are built with
 * the connection's own credentials, so two merchants using the same
 * carrier never share an instance, a token, or configuration.
 */
final class ShippingManager
{
    /**
     * @var array<string, CarrierDriverInterface>
     */
    private array $shared = [];

    /**
     * @var array<string, Closure(ShippingConnectionContext): CarrierDriverInterface>
     */
    private array $factories = [];

    /**
     * @param  Closure(ShippingConnectionContext): CarrierDriverInterface  $factory
     */
    public function extend(string $carrierCode, Closure $factory): void
    {
        $this->factories[$carrierCode] = $factory;
    }

    public function driverFor(ShippingConnectionContext $connection): CarrierDriverInterface
    {
        $factory = $this->factories[$connection->carrierCode] ?? null;

        if ($factory === null) {
            throw ShippingException::unknownCarrier($connection->carrierCode);
        }

        return $factory($connection);
    }

    /**
     * Per-connection driver when a factory exists, shared driver as a
     * fallback (fake and other credential-less drivers). Used by flows
     * like webhook verification that carry no connection credentials.
     */
    public function driverForOrShared(ShippingConnectionContext $connection): CarrierDriverInterface
    {
        if (isset($this->factories[$connection->carrierCode])) {
            return $this->driverFor($connection);
        }

        return $this->shared($connection->carrierCode);
    }

    /**
     * Shared (credential-less) drivers, e.g. fake. Never use for carriers
     * with per-connection credentials.
     */
    public function share(string $carrierCode, CarrierDriverInterface $driver): void
    {
        $this->shared[$carrierCode] = $driver;
    }

    public function shared(string $carrierCode): CarrierDriverInterface
    {
        return $this->shared[$carrierCode]
            ?? throw ShippingException::unknownCarrier($carrierCode);
    }

    /**
     * Registered carrier codes (factories first, then shared drivers).
     *
     * @return list<string>
     */
    public function registered(): array
    {
        return array_values(array_unique([
            ...array_keys($this->factories),
            ...array_keys($this->shared),
        ]));
    }
}
