<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Console;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;
use Illuminate\Console\Command;
use Throwable;

class ShippingDriversCommand extends Command
{
    protected $signature = 'shipping:drivers';

    protected $description = 'List registered shipping drivers and their declared capabilities.';

    public function handle(ShippingManager $manager): int
    {
        $rows = [];

        foreach ($manager->registered() as $code) {
            try {
                $driver = $manager->driverFor(new ShippingConnectionContext(
                    connectionId: 'diagnostics',
                    carrierCode: $code,
                    mode: ConnectionMode::Platform,
                    environment: Environment::Sandbox,
                ));
                $capabilities = array_keys(array_filter($driver->capabilities()->toArray()));
                $rows[] = [$code, get_class($driver), implode(', ', $capabilities)];
            } catch (Throwable $e) {
                $rows[] = [$code, 'unresolvable: '.$e->getMessage(), '—'];
            }
        }

        if ($rows === []) {
            $this->warn('No shipping drivers registered. Use ShippingManager::extend() to add carriers.');

            return self::SUCCESS;
        }

        $this->table(['Carrier', 'Driver', 'Capabilities'], $rows);

        return self::SUCCESS;
    }
}
