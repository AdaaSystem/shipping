<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel\Jobs;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Laravel\Events\ShipmentCreated;
use Adaa\Shipping\Laravel\Events\ShipmentFailed;
use Adaa\Shipping\Manager\ShippingManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Create one carrier shipment in the background.
 *
 * The host persists the result by listening to ShipmentCreated /
 * ShipmentFailed (idempotency lives in the host's storage, keyed by
 * the draft's idempotency key). Only one job per idempotency key or
 * reference may exist at a time.
 *
 * SECURITY: the connection array may carry credentials, which would
 * then sit in the jobs table until processed. Prefer passing a
 * reference (connection id + carrier) and rebuilding the context with
 * fresh credentials via a subclass when your threat model requires it.
 */
class CreateShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $shippingConnection  ShippingConnectionContext::toArray() shape.
     * @param  array<string, mixed>  $draft  ShipmentDraft::toArray() shape.
     */
    public function __construct(
        public readonly array $shippingConnection,
        public readonly array $draft,
    ) {}

    public function uniqueId(): string
    {
        $key = $this->draft['idempotency_key'] ?? null;

        if (is_string($key) && $key !== '') {
            return 'shipment:'.$key;
        }

        $reference = $this->draft['reference'] ?? null;

        return 'shipment:'.(string) ($this->shippingConnection['connection_id'] ?? 'unknown')
            .':'.(is_string($reference) && $reference !== '' ? $reference : uniqid('', true));
    }

    public function handle(ShippingManager $manager): void
    {
        $context = ShippingConnectionContext::fromArray($this->shippingConnection);
        $draft = ShipmentDraft::fromArray($this->draft);

        try {
            $result = $manager->driverFor($context)->createShipment($draft, $context);
        } catch (ShippingException $exception) {
            ShipmentFailed::dispatch(
                $context->connectionId,
                $context->carrierCode,
                $exception->getMessage(),
                $draft->reference,
            );

            throw $exception;
        }

        ShipmentCreated::dispatch($context->connectionId, $context->carrierCode, $result);
    }
}
