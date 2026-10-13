<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Console;

use Adaa\Shipping\Registry\CarrierRegistry;
use Illuminate\Console\Command;

class ShippingDiagnoseCommand extends Command
{
    protected $signature = 'shipping:diagnose {carrier : Carrier code, e.g. bosta}';

    protected $description = 'Show static registry metadata and required credentials for a carrier (no live calls, no secrets).';

    public function handle(CarrierRegistry $registry): int
    {
        $code = (string) $this->argument('carrier');
        $entry = $registry->get($code);

        if ($entry === null) {
            $this->error("Unknown carrier [{$code}]. Known: ".implode(', ', array_keys($registry->all())));

            return self::FAILURE;
        }

        $this->info((string) ($entry['name'] ?? $code));

        $this->table(
            ['Key', 'Value'],
            collect($entry)
                ->except('capabilities')
                ->map(fn ($value, $key): array => [
                    (string) $key,
                    is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value,
                ])
                ->values()
                ->all(),
        );

        $capabilities = isset($entry['capabilities']) && is_array($entry['capabilities'])
            ? array_keys(array_filter($entry['capabilities']))
            : [];

        $this->line('Capabilities: '.($capabilities === [] ? '—' : implode(', ', $capabilities)));

        return self::SUCCESS;
    }
}
