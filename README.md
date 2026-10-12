# Adaa Shipping

مكتبة شحن PHP متعددة الشركات، framework-agnostic في النواة، مع تكامل اختياري مع Laravel وواجهة Filament منفصلة.

Framework-agnostic multi-carrier shipping library for PHP with optional Laravel integration and a separate Filament UI package.

## الفكرة

- **اتصالان مستقلان:** حسابات المنصة (`platform`) وحسابات التجار (`merchant`) — كل متجر يربط عقده الخاص دون تداخل.
- **قدرات اختيارية:** كل سائق يعلن ما يدعمه فعلًا (تسعير/إنشاء/ملصق/تتبع/إلغاء/استلام/مرتجع/COD) — لا واجهة موحدة قسرية.
- **توحيد الحالات والأخطاء** عبر `StatusNormalizer` دون فقدان بيانات الناقل (`raw`).
- **تعمل بدون Filament** طبيعيًا؛ شاشات الإدارة في حزمة `adaa/shipping-filament` المنفصلة.

## التثبيت

```bash
composer require adaa/shipping
```

## استخدام سريع (خارج Laravel)

```php
use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;

$manager = new ShippingManager;
$manager->extend('fake', fn () => new \Adaa\Shipping\Drivers\FakeDriver);

$connection = new ShippingConnectionContext(
    connectionId: 'conn_1',
    carrierCode: 'fake',
    mode: ConnectionMode::Merchant,
    merchantId: 'merchant_7',
    environment: Environment::Sandbox,
    credentials: [],
);

$shipment = $manager->driverFor($connection)->createShipment(new ShipmentDraft(
    origin: new Address(name: 'Warehouse', line1: '1 Industrial Rd', city: 'Cairo', countryCode: 'EG'),
    destination: new Address(name: 'Ahmed', line1: '12 Nile St', city: 'Giza', countryCode: 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 1.2)],
    reference: 'ORD-42',
));
```

## داخل Laravel

```bash
php artisan vendor:publish --tag=shipping-config
php artisan vendor:publish --tag=shipping-migrations
php artisan migrate
```

انظر `docs/shipping-plan.md` في مشروع المتجر لخطة التنفيذ الكاملة.

## الاختبارات

```bash
composer test
```

## الترخيص

MIT
