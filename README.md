# Adaa Shipping

> **English** | [العربية](README.ar.md)

Multi-carrier shipping for PHP with first-class Laravel support. Connect once per carrier **account** — the platform's own accounts and every merchant's own accounts stay fully isolated.

```bash
composer require adaa/shipping
```

## 60-second start (no keys needed)

```php
use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;

$manager = new ShippingManager;
$manager->share('fake', new FakeDriver);

$connection = new ShippingConnectionContext(
    connectionId: 'conn_1',
    carrierCode: 'fake',
    mode: ConnectionMode::Merchant,
    environment: Environment::Sandbox,
    merchantId: 'merchant_7',
);

$shipment = $manager->shared('fake')->createShipment(new ShipmentDraft(
    origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
    destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt')],
    reference: 'ORD-1001',
), $connection);

echo $shipment->trackingNumber; // e.g. SHP00000001
```

## Core concepts (read once)

| Class | What it is |
|---|---|
| `ShippingConnectionContext` | One carrier **account**: `connectionId`, `carrierCode`, `mode` (`platform`/`merchant`), `environment`, `credentials` (in-memory only), `merchantId`, `storeId`, `externalAccountId`. |
| `ShippingManager` | Resolves drivers. `extend($code, fn ($ctx) => ...)` registers a carrier; `driverFor($connection)` builds it **from that connection's credentials**; `shared($code)` returns credential-less drivers (fake). |
| `CarrierDriverInterface` | What every driver implements: `capabilities()` + `createShipment()`. Everything else (`SupportsRates`, `SupportsTracking`, `SupportsLabels`, `SupportsCancellation`) is opt-in — check `capabilities()` or `instanceof` before calling. |
| DTOs | `ShipmentDraft` (in) → `ShipmentResult` (`id`, `trackingNumber`, `status`, `labelUrl`, `rate`, `currency`, `raw`) → `TrackingResult` (`status`, `events[]`) → `LabelResult` (`contents`, `base64Encoded`, `url`). |

## Connecting a real carrier

Register the driver once (e.g. in a service provider), passing each connection's own credentials:

```php
use Adaa\Shipping\Drivers\Bosta\BostaDriver;
use Illuminate\Http\Client\Factory as HttpFactory;

$manager->extend('bosta', fn ($ctx) => BostaDriver::fromConnection(app(HttpFactory::class), $ctx));
```

`fromConnection()` exists on **every** driver with the same signature. Credentials come from your connection row (encrypted in your DB) — never from `.env` when you serve multiple merchants.

| Carrier | Credential fields in `credentials` | Where to get them |
|---|---|---|
| Bosta | `api_key`, `base_url` (`https://app.bosta.co`, staging `https://stg-app.bosta.co`) | Business account → API Integration ([docs](https://docs.bosta.co)) |
| Mylerz | `username`, `password` (auto-exchanged for a token) or static `token` | Business account, request Integration API access ([site](https://www.mylerz.com)) |
| SMSA | `passkey` | Contracted SECOM account ([site](https://www.smsaexpress.com)) |
| Aramex | `username`, `password`, `account_number`, `account_pin`, `account_entity`, `account_country_code` | [Developer portal](https://www.aramex.com/developers), test host `ws.dev.aramex.net` |
| DHL Express | `username`, `password`, `account_number` (HTTP Basic) | [MyDHL portal](https://developer.dhl.com) |
| FedEx | `client_id`, `client_secret`, `account_number` (OAuth2) | [Developer portal](https://developer.fedex.com), sandbox `apis-sandbox.fedex.com` |
| UPS | `client_id`, `client_secret`, `account_number` (OAuth2) | [Developer portal](https://developer.ups.com), sandbox `wwwcie.ups.com` |
| J&T Express Egypt | `api_account`, `customer_code`, `private_key`, `customer_pwd` | Merchant onboarding ([site](https://www.jtexpress-eg.com)) |
| MNG Kargo | `client_id`, `client_secret` (IBM app) + `customer_number`, `password` (panel, JWT) | ApiZone portal ([site](https://www.mngkargo.com.tr)), sandbox `testapi.mngkargo.com.tr` |
| HepsiJet | `username`, `password` (Basic → `X-Auth-Token`) + `abbreviation_code`, `company_name`, `company_address_id`, `current_xdock_code` | Merchant onboarding, sandbox `integration-apitest.hepsijet.com` |
| Yurtiçi Kargo | `ws_user_name`, `ws_password` (+ `ws_language`, default `TR`) | Branch-issued WS credentials ([site](https://www.yurticikargo.com)) |
| Aras Kargo | `username`, `password` + `customer_code` (Esasweb tracking; optional `tracking_endpoint`) | Branch SetOrder user + Esasweb ([site](https://www.araskargo.com.tr)) |
| Sürat Kargo | `kullanici_adi`, `sifre` (+ `web_sifre` for create) | Branch-issued SOAP credentials, test `prova.suratkargo.com.tr` ([site](https://www.suratkargo.com.tr)) |
| PTT Kargo | `musteri_id`, `sifre` (+ `kullanici`, default `PttWs`; pre-allocated `metadata.barcode`) | PTT corporate WS ([site](https://www.ptt.gov.tr)) |
| Egypt Post | — (tracking only, no creation API exists) | — |

Common optional keys every HTTP driver honors: `base_url` / `endpoint` override, `timeout` (seconds), `environment` (`sandbox`|`production`).

## Operations reference

All examples assume `$driver = $manager->driverFor($connection)` and throw `Adaa\Shipping\Exceptions\ShippingException` on carrier failure.

### Create a shipment (+ COD)

```php
$shipment = $driver->createShipment(new ShipmentDraft(
    origin: new Address(name: 'Store', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG', phone: '01011111111'),
    destination: new Address(name: 'Sara', line1: '5 Zamalek St', city: 'Cairo', countryCode: 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 2.0, description: 'Shoes', quantity: 1)],
    reference: 'ORD-1002',                       // your order id
    metadata: ['cod' => 750],                    // cash on delivery (carrier converts it)
    idempotencyKey: 'ord-1002-bosta',            // safe to retry with the same key
), $connection);

$shipment->id;              // carrier shipment id
$shipment->trackingNumber;  // track with this
$shipment->labelUrl;        // when the carrier returns one inline
```

Per-carrier extras go in `metadata` (see each driver's `*PayloadFactory`): Bosta `city_code`/`zone`/`district`/`building_number`/`notes`/`webhook_url`; J&T `express_type`/`service_type`; UPS `service_code` (`11` = standard); FedEx `service_type`; Aramex product type, etc.

### Get rates (where supported)

```php
if ($driver instanceof SupportsRates) {
    foreach ($driver->rates($draft, $connection) as $quote) {
        echo "{$quote->serviceName}: {$quote->amount} {$quote->currency}\n";
        // $quote->serviceCode, $quote->estimatedDelivery
    }
}
```

### Track

```php
$tracking = $driver->track('BOSTA-TRACKING-NO', $connection);
echo $tracking->status->value; // created|labeled|picked_up|in_transit|out_for_delivery|delivered|exception|cancelled|returned|exchanged
foreach ($tracking->events as $event) {
    echo "{$event->occurredAt} [{$event->location}] {$event->description}\n";
}
```

### Labels

```php
$label = $driver->label($shipment->id, $connection, LabelFormat::Pdf);

if ($label->url !== null) {
    // redirect / store the URL
} else {
    file_put_contents('label.pdf', $label->base64Encoded ? base64_decode($label->contents) : $label->contents);
}
```

### Cancel

```php
if ($driver instanceof SupportsCancellation) {
    $driver->cancel($shipment->id, $connection); // bool
}
```

### Returns & exchanges

No separate endpoints — carriers model these as delivery types on creation:

```php
use Adaa\Shipping\Drivers\Bosta\DeliveryType;

$return = $driver->createShipment(new ShipmentDraft(
    origin: $customerAddress,      // picked up FROM the customer
    destination: $warehouseAddress,
    parcels: [new Parcel(weightKg: 1.0)],
    reference: 'RET-501',
    metadata: ['type' => DeliveryType::CRP],   // Customer Return Pickup
), $connection);

$exchange = $driver->createShipment(new ShipmentDraft(
    origin: $warehouseAddress,
    destination: $customerAddress,
    parcels: [new Parcel(weightKg: 1.0, description: 'Size M')],
    reference: 'EXC-501',
    metadata: ['type' => DeliveryType::EXCHANGE],
), $connection);
```

## Two accounts, same carrier (the whole point)

```php
$platform = new ShippingConnectionContext('conn_platform', 'bosta', ConnectionMode::Platform, Environment::Production, ['api_key' => 'PLATFORM_KEY']);
$merchant = new ShippingConnectionContext('conn_42', 'bosta', ConnectionMode::Merchant, Environment::Production, ['api_key' => 'MERCHANT_KEY'], merchantId: 'm42');

// Different instances, different credentials, zero shared state:
$manager->driverFor($platform)->createShipment($draftA, $platform);
$manager->driverFor($merchant)->createShipment($draftB, $merchant);
```

## Laravel

Publish the config, then resolve per request from **your** connection rows (encrypted credentials in DB):

```bash
php artisan vendor:publish --tag=shipping-config
```

```php
// Migration sketch for your connections table:
Schema::create('shipping_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = platform
    $table->string('carrier_code')->index();
    $table->string('mode')->default('platform');        // platform|merchant
    $table->string('environment')->default('sandbox');  // sandbox|production
    $table->json('credentials')->nullable();            // cast encrypted:array on the model + $hidden
    $table->string('status')->default('pending');
    $table->timestamps();
});
```

```php
// Resolve inside a job / controller — credentials live only for the call:
$context = new ShippingConnectionContext(
    connectionId: (string) $row->id,
    carrierCode: $row->carrier_code,
    mode: $row->mode === 'platform' ? ConnectionMode::Platform : ConnectionMode::Merchant,
    environment: Environment::from($row->environment),
    credentials: $row->credentials ?? [],   // decrypted via encrypted:array cast
    merchantId: $row->merchant_id ? (string) $row->merchant_id : null,
);

$result = app(ShippingManager::class)->driverFor($context)->createShipment($draft, $context);
```

Queue slow calls, key jobs by `connectionId`, and never log `$context->credentials`. Verify carrier webhooks per connection secret before trusting status updates.

## Statuses & errors

Carrier strings (`OFD`, `shipped`, …) normalize to `ShipmentStatus`; unknown values become `exception` instead of throwing, so webhooks stay resilient. `ShippingException::carrierFailed($message, $status)` carries the HTTP status; `shipmentNotFound()` uses 404. Drivers strip secrets from `raw` before returning it — still, never dump `raw` to logs in production.

## Testing your integration

```php
// Unit / feature tests: FakeDriver is deterministic.
$driver = new FakeDriver;
$shipment = $driver->createShipment($draft, $connection);
$driver->advance($shipment->trackingNumber, ShipmentStatus::Delivered);
$this->assertEquals(ShipmentStatus::Delivered, $driver->track($shipment->trackingNumber, $connection)->status);

// HTTP-level tests: inject Illuminate\Http\Client\Factory and fake it.
$http = new Factory;
$http->fake(['*' => Http::response([...], 200)]);
$driver = BostaDriver::fromConnection($http, $connection);
```

## Supported drivers

Bosta, Mylerz, SMSA, Aramex, DHL Express, FedEx, UPS, J&T Express Egypt — full create/track/label (plus cancel/return/exchange/COD where the carrier API supports it; check `$driver->capabilities()`); MNG Kargo, Yurtiçi Kargo, Sürat Kargo, PTT Kargo — create/track/cancel/return/exchange/COD, labels honestly disabled (ZPL/EPL only at create time or barcode-print; check `$driver->capabilities()`); HepsiJet — create/track/label/return/exchange/COD (no cancel endpoint); Aras Kargo — create/track/label/cancel/return/exchange/COD; Egypt Post tracking only; `FakeDriver` for tests. Adding a carrier = new driver + `extend()`, core untouched.

Companion admin UI: [`adaa/shipping-filament`](https://github.com/AdaaSystem/shipping-filament) (optional Filament resources + dashboard).

## Contributing

MIT. One rule: a test for every change, Pint before PRs, and never declare an operation a carrier does not support — verify against the real API and note the evidence.

## License

MIT
