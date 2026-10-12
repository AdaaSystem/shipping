<?php

declare(strict_types=1);

namespace Adaa\Shipping\Connection;

use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;

/**
 * Resolved shipping connection for exactly one shipment operation.
 *
 * Credentials live only in memory for the duration of the call and must
 * never be logged, serialized to responses, or cached. Use a per-connection
 * cache key (shipping:token:{connectionId}) for derived tokens.
 */
final readonly class ShippingConnectionContext
{
    /**
     * @param  array<string, mixed>  $credentials  Decrypted only at resolution time.
     * @param  array<string, mixed>  $metadata  Provider-specific, non-secret data.
     */
    public function __construct(
        public string $connectionId,
        public string $carrierCode,
        public ConnectionMode $mode,
        public Environment $environment,
        public array $credentials = [],
        public ?string $merchantId = null,
        public ?string $storeId = null,
        public ?string $externalAccountId = null,
        public array $metadata = [],
    ) {}

    public function isPlatform(): bool
    {
        return $this->mode === ConnectionMode::Platform;
    }

    public function cacheKey(string $suffix): string
    {
        return "shipping:token:{$this->connectionId}:{$suffix}";
    }
}
