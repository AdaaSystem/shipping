<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
use Adaa\Shipping\Contracts\SupportsCancellation;
use Adaa\Shipping\Contracts\SupportsLabels;
use Adaa\Shipping\Contracts\SupportsRates;
use Adaa\Shipping\Contracts\SupportsTracking;
use Adaa\Shipping\DTOs\LabelResult;
use Adaa\Shipping\DTOs\RateQuote;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\DTOs\ShipmentResult;
use Adaa\Shipping\DTOs\TrackingEvent;
use Adaa\Shipping\DTOs\TrackingResult;
use Adaa\Shipping\Enums\LabelFormat;
use Adaa\Shipping\Enums\ShipmentStatus;
use Adaa\Shipping\Exceptions\ShippingException;

/**
 * In-memory driver for tests and local development. Shared instances are
 * safe because no credentials are involved.
 */
final class FakeDriver implements CarrierDriverInterface, SupportsCancellation, SupportsLabels, SupportsRates, SupportsTracking
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $byId = [];

    /**
     * @var array<string, string>
     */
    private array $idByTracking = [];

    private int $sequence = 0;

    public function capabilities(): CarrierCapabilities
    {
        return new CarrierCapabilities(
            rates: true,
            create: true,
            label: true,
            track: true,
            cancel: true,
            cod: true,
            webhooks: true,
        );
    }

    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array
    {
        return [
            new RateQuote(
                carrierCode: $connection->carrierCode,
                serviceCode: 'standard',
                serviceName: 'Standard',
                amount: 25.0,
                currency: 'EGP',
            ),
        ];
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $this->sequence++;
        $id = sprintf('SHP-%04d', $this->sequence);
        $tracking = sprintf('SHP%08d', $this->sequence);

        $this->byId[$id] = [
            'id' => $id,
            'tracking_number' => $tracking,
            'status' => ShipmentStatus::Created,
            'reference' => $draft->reference,
            'connection_id' => $connection->connectionId,
            'events' => [new TrackingEvent(status: ShipmentStatus::Created, description: 'Shipment recorded')],
        ];
        $this->idByTracking[$tracking] = $id;

        return new ShipmentResult(
            id: $id,
            trackingNumber: $tracking,
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $shipment = $this->findByTracking($trackingNumber);

        /** @var list<TrackingEvent> $events */
        $events = $shipment['events'];

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $shipment['status'],
            events: $events,
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $this->findById($shipmentId);

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: base64_encode('%PDF-1.4 fake-label'),
            base64Encoded: true,
            url: "https://shipping.test/labels/{$shipmentId}.{$format->value}",
        );
    }

    public function cancel(string $shipmentId, ShippingConnectionContext $connection): bool
    {
        $shipment = $this->findById($shipmentId);
        $shipment['status'] = ShipmentStatus::Cancelled;
        $this->byId[$shipmentId] = $shipment;

        return true;
    }

    public function advance(string $trackingNumber, ShipmentStatus $status, string $description = ''): void
    {
        $id = $this->idByTracking[$trackingNumber] ?? null;

        if ($id === null) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $shipment = $this->byId[$id];
        $shipment['status'] = $status;
        $shipment['events'][] = new TrackingEvent(
            status: $status,
            description: $description !== '' ? $description : $status->value,
        );
        $this->byId[$id] = $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    private function findById(string $shipmentId): array
    {
        if (! isset($this->byId[$shipmentId])) {
            throw ShippingException::shipmentNotFound($shipmentId);
        }

        return $this->byId[$shipmentId];
    }

    /**
     * @return array<string, mixed>
     */
    private function findByTracking(string $trackingNumber): array
    {
        $id = $this->idByTracking[$trackingNumber] ?? null;

        if ($id === null) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        return $this->byId[$id];
    }
}
