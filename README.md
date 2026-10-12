# Adaa Shipping

> **English** | [العربية](README.ar.md)

Framework-agnostic multi-carrier shipping library for PHP with first-class Laravel support — built for **multi-vendor marketplaces** where every merchant connects their **own** carrier account, alongside the platform's **own** accounts, with zero cross-contamination.

## Why this package?

Most shipping libraries assume **one account per carrier** (a single API key in `.env`). That breaks the marketplace model:

- Merchant A holds a Bosta contract with **their** key; merchant B holds a **different** Bosta contract;
- The platform itself holds a third account for subsidized shipping.

`adaa/shipping` solves this with a **connection context** (`ShippingConnectionContext`): every shipment runs through a driver built from **that connection's own credentials** — no global config, no shared keys, no cross-merchant leaks.

## Highlights

- **Two connection modes** — `platform` (marketplace-owned accounts) and `merchant` (merchant-owned accounts), fully isolated.
- **Opt-in capabilities** — each driver declares only what it truly supports (`rates`, `create`, `label`, `track`, `cancel`, `pickup`, `return`, `exchange`, `cod`, `webhooks`…). Tracking-only carriers are never offered for creation.
- **Normalized statuses & errors** (`ShipmentStatus` + `StatusNormalizer`) while preserving the carrier's raw payload (`raw`) with secrets redacted.
- **Framework-agnostic core** — works in any PHP project; Laravel bridge is optional; the Filament admin UI lives in a separate package (`adaa/shipping-filament`), so this library runs perfectly without Filament.
- **Reliability built in** — idempotency keys, queued dispatch, per-connection signed webhooks, and an audit trail.

## Requirements

- PHP `^8.3`
- `illuminate/contracts`, `illuminate/support`, `illuminate/http` (`^10.0|^11.0|^12.0|^13.0`) — the core only needs an injectable HTTP client; no facades outside the Laravel bridge.

## Installation

```bash
composer require adaa/shipping
```

## Quick start (plain PHP)

```php
use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;

$manager = new ShippingManager();

// Register a driver: the factory receives the connection context,
// so the driver is always built with that connection's credentials.
$manager->extend('bosta', fn (ShippingConnectionContext $c) => BostaDriver::fromConnection($http, $c));

// One merchant's connection — invisible to, and unshared with, everyone else.
$connection = new ShippingConnectionContext(
    connectionId: 'conn_42',
    carrierCode: 'bosta',
    mode: ConnectionMode::Merchant,
    environment: Environment::Production,
    credentials: ['api_key' => '...'],   // in-memory only — never logged or returned
    merchantId: 'merchant_7',
);

$shipment = $manager->driverFor($connection)->createShipment(new ShipmentDraft(
    origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
    destination: new Address(name: 'Ahmed Ali', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 1.5, description: 'T-shirt')],
    reference: 'ORD-1001',
    metadata: ['cod' => 450, 'city_code' => 'EG-02'],
    idempotencyKey: 'ord-1001-bosta',
));

echo $shipment->trackingNumber;
```

## Laravel integration

```bash
php artisan vendor:publish --tag=shipping-config
```

```php
use Adaa\Shipping\Manager\ShippingManager;

$result = app(ShippingManager::class)->driverFor($connection)->createShipment($draft);
```

Suggested schema (publish your own migrations or bring your models):

| Table | Role |
|---|---|
| `shipping_connections` | One row per connection: nullable `merchant_id` (NULL = platform), `carrier_code`, **encrypted** `credentials`, `environment`, `status`, … |
| `shipping_shipments` | One row per shipment: `order_id`, `connection_id`, `tracking_number`, unique `idempotency_key`, normalized status, … |
| `shipping_events` | Audit trail for every status transition. |

> Golden rule: with multiple merchants, never put carrier keys in `.env` — each connection carries its own encrypted credentials in the database.

## Carrier eligibility

Never use the merchant's country alone. `CarrierRegistry` (static metadata: origin countries, services) is combined at runtime with the merchant's active connections and each connection's capabilities:

```php
$eligible = app(\Adaa\Shipping\Registry\CarrierRegistry::class)->eligibleForOrigin('EG');
// e.g. ['aramex', 'bosta', 'egyptpost', 'fake', 'mylerz'] — then filtered by active connections + capabilities.
```

## Drivers

| Carrier | Region | Type | create | track | label | return/exchange | COD | Status |
|---|---|---|---|---|---|---|---|---|
| Bosta | Egypt | direct | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mylerz | Egypt/MENA | direct | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SMSA | KSA/GCC | direct SOAP (raw XML, no `ext-soap`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Aramex | MENA/Global | direct SOAP (raw XML + `CalculateRate`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| DHL Express | Global | direct REST, MyDHL (Basic Auth) | ✅ | ✅ | inline-at-creation only | — | — | ✅ (rates + create + track) |
| FedEx | Global | direct REST (OAuth2) | ✅ | ✅ | ✅ (`encodedLabel` preserved) | ✅ | ✅ | ✅ |
| UPS | Global | direct REST (OAuth2) | ✅ | ✅ | ✅ (`GraphicImage` recovery) | — | ✅ | ✅ (Shop rates + void cancel) |
| J&T Express | Egypt | direct | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Egypt Post | Egypt | **tracking only** | ❌ | ✅ | public URL only | — | — | ✅ (no public creation API exists) |
| Fake | Tests | in-memory | ✅ | ✅ | ✅ | — | ✅ | ✅ |

> Adding a carrier = a new driver + `extend()` — the core stays untouched. A driver must never claim an operation it does not support.

## Reliability

- **Idempotency** — `idempotency_key` (unique) prevents duplicate carrier shipments on retries.
- **Queues** — slow operations run as jobs with backoff that never re-creates carrier shipments.
- **Webhooks** — signed per connection, with replay/duplicate protection and terminal-state guards.
- **Secrets** — encrypted at rest, redacted from `raw` payloads, never in logs, exceptions, or API responses.

## Testing

```bash
composer test      # Pest: Unit + Feature
composer run format    # Pint
composer run analyse   # PHPStan
```

Coverage includes: status normalization, capability declarations, connection isolation (two merchants, same carrier, different keys), idempotency, and webhook signatures. Live-sandbox tests are tagged `group=live` and require real credentials.

## Companion package

- **`adaa/shipping-filament`** — optional Filament resources (connections/shipments) for admins and merchants. The core is complete without it.

## Roadmap

- [x] Core + `FakeDriver` + Laravel bridge + carrier registry
- [x] Bosta, Mylerz, SMSA, Aramex, DHL, FedEx, UPS, J&T drivers + Egypt Post tracking
- [ ] Jobs + signed webhooks + Artisan diagnostics in the Laravel bridge
- [x] Filament companion package + CI + automated releases (`v0.1.0`)

## Releases & automatic updates

New versions flow to every project automatically — no reinstall, no re-submit:

1. **Maintainer tags a release:** `git tag v0.2.0 && git push --tags`
2. **GitHub Action** (`.github/workflows/release.yml`) creates the GitHub Release with auto-generated notes.
3. **Packagist syncs itself** via the GitHub integration (one-time setup below) — the new version appears within minutes.
4. **Consumer projects** get it with a normal `composer update adaa/shipping` (or automatically via Dependabot/Renovate).

One-time Packagist setup (do it once per package):

1. Submit the repo (first time only): <https://packagist.org/packages/submit> — enter `https://github.com/AdaaSystem/shipping`.
2. Enable auto-sync: open <https://packagist.org/profile>, authorize the **Packagist GitHub App** for the `AdaaSystem` organization. From then on every tag syncs without clicks (see <https://packagist.org/about#how-to-update-packages>).

CI runs on every push/PR (PHP 8.3–8.5: `composer validate`, Pint, Pest) and Dependabot opens weekly dependency PRs.

## Contributing

Open source (MIT). Please: a test for every change, Pint before any PR, and never declare an unsupported operation — verify against the carrier's actual API and mark the evidence.

## Credits

Design inspired by [ShipBridge](https://github.com/mohamedhekal/shipbridge) (DTOs, status normalization, Client/PayloadFactory/Driver pattern) and [verbb/shippy](https://github.com/verbb/shippy) (natural multi-account design, comparative rates). Carrier mappings cross-checked against the most-adopted open-source SDKs per carrier (e.g. `octw/aramex`, `korbeil/dhl-express-php-api`, `WhatArmy/FedexRest`, UPS OpenAPI).

## License

MIT
