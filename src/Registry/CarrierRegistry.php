<?php

declare(strict_types=1);

namespace Adaa\Shipping\Registry;

/**
 * Static carrier metadata (countries, services, declared capabilities).
 * Availability of a carrier is distinct from authorization of a merchant
 * and from live service availability — the marketplace combines this
 * registry with the merchant's active connections at runtime.
 */
final class CarrierRegistry
{
    /**
     * @param  array<string, array<string, mixed>>  $carriers
     */
    public function __construct(
        private readonly array $carriers = [],
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->carriers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $carrierCode): ?array
    {
        return $this->carriers[$carrierCode] ?? null;
    }

    /**
     * Carrier codes eligible for an origin country (static metadata only).
     *
     * @return list<string>
     */
    public function eligibleForOrigin(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        $codes = [];

        foreach ($this->carriers as $code => $meta) {
            $origins = array_map('strtoupper', (array) ($meta['origin_countries'] ?? []));

            if ($origins === [] || in_array($countryCode, $origins, true) || in_array('*', $origins, true)) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $codes;
    }
}
