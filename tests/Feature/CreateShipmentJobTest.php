<?php

declare(strict_types=1);

use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\Exceptions\ShippingException;
use Adaa\Shipping\Laravel\Events\ShipmentCreated;
use Adaa\Shipping\Laravel\Events\ShipmentFailed;
use Adaa\Shipping\Laravel\Jobs\CreateShipmentJob;
use Adaa\Shipping\Manager\ShippingManager;
use Illuminate\Support\Facades\Event;

function fakeJobConnection(): array
{
    return [
        'connection_id' => 'conn_1',
        'carrier_code' => 'fake',
        'mode' => 'merchant',
        'environment' => 'sandbox',
        'credentials' => [],
        'merchant_id' => 'm1',
    ];
}

function fakeJobDraft(): array
{
    return [
        'origin' => ['name' => 'W', 'line1' => 'l1', 'city' => 'Cairo', 'country_code' => 'EG'],
        'destination' => ['name' => 'C', 'line1' => 'l2', 'city' => 'Giza', 'country_code' => 'EG', 'phone' => '010'],
        'parcels' => [['weight_kg' => 1.0]],
        'reference' => 'R-1',
        'metadata' => [],
        'idempotency_key' => 'k-1',
    ];
}

beforeEach(function () {
    app(ShippingManager::class)->extend('fake', fn () => new FakeDriver);
});

test('job creates the shipment and dispatches the created event', function () {
    Event::fake([ShipmentCreated::class, ShipmentFailed::class]);

    CreateShipmentJob::dispatchSync(fakeJobConnection(), fakeJobDraft());

    Event::assertDispatched(ShipmentCreated::class, fn (ShipmentCreated $event): bool => $event->connectionId === 'conn_1'
        && $event->carrierCode === 'fake'
        && $event->shipment->trackingNumber !== '');
    Event::assertNotDispatched(ShipmentFailed::class);
});

test('job dispatches the failed event and rethrows for retry', function () {
    app(ShippingManager::class)->extend('fake', function () {
        throw ShippingException::carrierFailed('carrier down');
    });
    Event::fake([ShipmentCreated::class, ShipmentFailed::class]);

    try {
        CreateShipmentJob::dispatchSync(fakeJobConnection(), fakeJobDraft());

        $this->fail('Expected the carrier failure to throw.');
    } catch (ShippingException) {
    }

    Event::assertDispatched(ShipmentFailed::class, fn (ShipmentFailed $event): bool => $event->error === 'carrier down'
        && $event->reference === 'R-1');
    Event::assertNotDispatched(ShipmentCreated::class);
});

test('job unique id follows the idempotency key', function () {
    $job = new CreateShipmentJob(fakeJobConnection(), fakeJobDraft());
    $noKey = new CreateShipmentJob(fakeJobConnection(), ['reference' => 'R-2']);

    expect($job->uniqueId())->toBe('shipment:k-1')
        ->and($noKey->uniqueId())->toBe('shipment:conn_1:R-2');
});
