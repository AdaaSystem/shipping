<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aramex;

use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Contracts\CarrierCapabilities;
use Adaa\Shipping\Contracts\CarrierDriverInterface;
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
use Adaa\Shipping\Support\StatusNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Native Aramex carrier driver (MENA/Global, Shipping API V2 over raw SOAP).
 *
 * No SupportsCancellation: the V2 API exposes CancelPickup (by PickupGUID,
 * for scheduled pickups — see octw/aramex) but no shipment-cancel
 * operation, and shipbridge-aramex surfaces only CreateShipments /
 * TrackShipments / PrintLabel. Cancelling is therefore honestly disabled.
 *
 * Return/exchange travel through the create payload (swapped parties +
 * metadata.product_type RTRN) — Aramex has no dedicated return endpoint.
 * CalculateRate prices one service type per call, hence a single quote.
 */
final class AramexDriver implements CarrierDriverInterface, SupportsLabels, SupportsRates, SupportsTracking
{
    private readonly AramexPayloadFactory $payloads;

    public function __construct(
        private readonly AramexSoapClient $client,
        private readonly StatusNormalizer $normalizer,
    ) {
        $this->payloads = new AramexPayloadFactory($this->client->config());
    }

    public static function fromConnection(HttpFactory $http, ShippingConnectionContext $connection, ?StatusNormalizer $normalizer = null): self
    {
        $config = $connection->credentials;
        $config['environment'] = $connection->environment->value;

        return new self(
            new AramexSoapClient($http, $config),
            $normalizer ?? new StatusNormalizer(self::defaultStatusMap()),
        );
    }

    /**
     * UpdateCode values (SH001…) plus UpdateDescription fallbacks, per the
     * shipbridge-aramex status map.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            'SH001' => 'created',
            'SH002' => 'created',
            'Data Received' => 'created',
            'SH003' => 'picked_up',
            'Collected' => 'picked_up',
            'SH004' => 'in_transit',
            'SH005' => 'in_transit',
            'In Transit' => 'in_transit',
            'SH006' => 'out_for_delivery',
            'Out for Delivery' => 'out_for_delivery',
            'Delivered' => 'delivered',
            'SH007' => 'returned',
            'Returned' => 'returned',
            'Returned to Shipper' => 'returned',
            'SH008' => 'exception',
            'Exception' => 'exception',
        ];
    }

    public function capabilities(): CarrierCapabilities
    {
        return new CarrierCapabilities(
            rates: true,
            create: true,
            label: true,
            track: true,
            cancel: false,
            return: true,
            exchange: true,
            cod: true,
            webhooks: false,
        );
    }

    public function createShipment(ShipmentDraft $draft, ShippingConnectionContext $connection): ShipmentResult
    {
        $result = $this->client->createShipments($this->payloads->create($draft));

        if ($result['has_errors']) {
            throw ShippingException::carrierFailed($this->firstMessage($result['notifications']));
        }

        $shipment = $result['shipments'][0] ?? null;

        if ($shipment === null) {
            throw ShippingException::carrierFailed('Aramex CreateShipments returned no ProcessedShipment.');
        }

        if ($shipment['has_errors']) {
            throw ShippingException::carrierFailed($this->firstMessage($shipment['notifications']));
        }

        if ($shipment['id'] === '') {
            throw ShippingException::carrierFailed('Aramex CreateShipments returned no AWB number.');
        }

        return new ShipmentResult(
            id: $shipment['id'],
            trackingNumber: $shipment['id'],
            status: ShipmentStatus::Created,
            carrier: $connection->carrierCode,
            labelUrl: $shipment['label_url'],
            raw: $this->sanitize([
                'awb' => $shipment['id'],
                'label_url' => $shipment['label_url'],
                'has_label_contents' => $shipment['label_contents'] !== null,
                'notifications' => $result['notifications'],
            ]),
        );
    }

    public function track(string $trackingNumber, ShippingConnectionContext $connection): TrackingResult
    {
        $result = $this->client->trackShipments($this->payloads->track($trackingNumber));

        if ($result['has_errors']) {
            throw ShippingException::carrierFailed($this->firstMessage($result['notifications']));
        }

        if ($result['updates'] === []) {
            throw ShippingException::shipmentNotFound($trackingNumber);
        }

        $status = ShipmentStatus::InTransit;

        /** @var list<TrackingEvent> $events */
        $events = [];

        foreach ($result['updates'] as $update) {
            $code = $update['code'] !== '' ? $update['code'] : $update['description'];
            $normalized = $this->normalizer->normalize($code !== '' ? $code : 'exception');
            $status = $normalized;

            $events[] = new TrackingEvent(
                status: $normalized,
                description: $update['description'] !== '' ? $update['description'] : $update['code'],
                occurredAt: $update['datetime'],
                location: $update['location'],
            );
        }

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $status,
            events: $events,
            raw: $this->sanitize([
                'awb' => $trackingNumber,
                'updates' => $result['updates'],
            ]),
        );
    }

    public function label(string $shipmentId, ShippingConnectionContext $connection, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $result = $this->client->printLabel($this->payloads->label($shipmentId));

        if ($result['has_errors']) {
            throw ShippingException::carrierFailed($this->firstMessage($result['notifications']));
        }

        $contents = $result['label_contents'] ?? '';

        if ($contents === '' && $result['label_url'] === null) {
            throw ShippingException::carrierFailed('Aramex PrintLabel returned an empty label.');
        }

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: $contents,
            base64Encoded: $contents !== '',
            url: $result['label_url'],
        );
    }

    /**
     * @return list<RateQuote>
     */
    public function rates(ShipmentDraft $draft, ShippingConnectionContext $connection): array
    {
        $params = $this->payloads->rate($draft);
        $result = $this->client->calculateRate($params);

        if ($result['has_errors']) {
            throw ShippingException::carrierFailed($this->firstMessage($result['notifications']));
        }

        if ($result['total_value'] === null) {
            throw ShippingException::carrierFailed('Aramex CalculateRate returned no TotalAmount.');
        }

        /** @var array<string, mixed> $details */
        $details = $params['ShipmentDetails'] ?? [];
        $serviceCode = (string) ($details['ProductGroup'] ?? 'EXP').':'.(string) ($details['ProductType'] ?? 'PPX');

        return [
            new RateQuote(
                carrierCode: $connection->carrierCode,
                serviceCode: $serviceCode,
                serviceName: 'Aramex '.$serviceCode,
                amount: $result['total_value'],
                currency: $result['total_currency'] ?? (string) ($params['PreferredCurrencyCode'] ?? 'USD'),
            ),
        ];
    }

    /**
     * @param  list<array{code: string, message: string}>  $notifications
     */
    private function firstMessage(array $notifications): string
    {
        foreach ($notifications as $notification) {
            if ($notification['message'] !== '') {
                return $notification['code'] !== ''
                    ? $notification['code'].': '.$notification['message']
                    : $notification['message'];
            }
        }

        return 'Aramex request failed.';
    }

    /**
     * Strip anything that looks like a secret before preserving carrier output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (['api_key', 'apikey', 'apiKey', 'token', 'authorization', 'password', 'secret', 'client_secret', 'username', 'user_name', 'account_number', 'account_pin', 'accountnumber', 'accountpin'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
