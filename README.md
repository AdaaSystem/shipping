# Adaa Shipping — مكتبة الشحن متعددة الشركات لـ PHP و Laravel

> **Multi-carrier shipping library for PHP with first-class Laravel support.**
> صُممت لمنصات التجارة متعددة البائعين (Multi-vendor Marketplaces): كل متجر يربط **حسابه الخاص** لدى شركة الشحن، والمنصة تدير **حساباتها الخاصة** — دون تداخل أبدًا.

---

## لماذا هذه المكتبة؟ | Why this package?

معظم مكتبات الشحن تفترض **حسابًا واحدًا** لكل شركة (مفتاح API في `.env`). هذا يكسر نموذج السوق المفتوح حيث:

- التاجر (أ) لديه عقد Bosta بمفتاحه، والتاجر (ب) لديه عقد Bosta بمفتاح **مختلف**،
- والمنصة نفسها لديها حساب ثالث للشحن المدعوم.

`adaa/shipping` تحل هذا عبر **سياق الاتصال** (`ShippingConnectionContext`): كل عملية شحن تُنفَّذ بسائق مبني **على بيانات اعتماد ذلك الاتصال تحديدًا** — لا إعدادات عمومية، لا مفاتيح مشتركة، لا تسرب بين التجار.

Most shipping libraries assume **one account per carrier** (an API key in `.env`). That breaks the marketplace model where merchant A and merchant B hold *different* contracts with the *same* carrier, plus a platform-owned account. This package resolves a driver **per connection**, from that connection's own credentials — no global config, no shared keys, no cross-merchant leaks.

## المزايا | Features

- **نموذج الحسابين:** `platform` (حسابات المنصة) و `merchant` (حسابات التجار) مع عزل كامل.
- **قدرات اختيارية:** كل سائق يعلن ما يدعمه فعلًا (`rates`, `create`, `label`, `track`, `cancel`, `pickup`, `return`, `exchange`, `cod`, `webhooks`…) — لا تُجبر شركة على عمليات وهمية، وشركات التتبع فقط لا تُعرض للإنشاء أبدًا.
- **توحيد الحالات والأخطاء** (`ShipmentStatus` + `StatusNormalizer`) مع الاحتفاظ برد الناقل الخام (`raw`) بعد تنقية الأسرار.
- **نواة framework-agnostic:** تعمل في أي مشروع PHP؛ تكامل Laravel اختياري؛ واجهة Filament في حزمة منفصلة (`adaa/shipping-filament`) — المكتبة تعمل بدونهما طبيعيًا.
- **موثوقية:** مفاتيح idempotency، طوابير، webhooks موقعة لكل اتصال، وسجل تدقيق.

## المتطلبات | Requirements

- PHP `^8.3`
- `illuminate/contracts`, `illuminate/support`, `illuminate/http` (`^10.0|^11.0|^12.0|^13.0`) — للنواة فقط عميل HTTP قابل للحقن، بلا Facades خارج طبقة Laravel.

## التثبيت | Installation

```bash
composer require adaa/shipping
```

## البدء السريع (PHP عادي) | Quick start (plain PHP)

```php
use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;

$manager = new ShippingManager();

// سجّل سائقًا: المصنع يستقبل سياق الاتصال فيُبنى السائق بمفاتيحه.
$manager->extend('bosta', fn (ShippingConnectionContext $c) => new BostaDriver(
    new BostaClient($http, $c->credentials + ['environment' => $c->environment->value]),
    ...
));

// اتصال تاجر — لا يرى غيره ولا يشاركه أحد.
$connection = new ShippingConnectionContext(
    connectionId: 'conn_42',
    carrierCode: 'bosta',
    mode: ConnectionMode::Merchant,
    environment: Environment::Production,
    credentials: ['api_key' => '...'],   // بالذاكرة فقط — لا تُسجَّل ولا تُعرض أبدًا
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

echo $shipment->trackingNumber; // رقم التتبع
```

## داخل Laravel | Laravel integration

```bash
php artisan vendor:publish --tag=shipping-config
```

```php
// config/shipping.php — بنية فقط، الأسرار في قاعدة البيانات مشفرة لكل اتصال.
use Adaa\Shipping\Manager\ShippingManager;

$manager = app(ShippingManager::class);
$result = $manager->driverFor($connection)->createShipment($draft);
```

نموذج البيانات المقترح (انشر migrations الخاصة بك أو استخدم حزمتك):

| الجدول | الدور |
|---|---|
| `shipping_connections` | اتصال واحد = (`merchant_id` فارغ للمنصة، `carrier_code`، `credentials` مشفرة، `environment`، `status`…) |
| `shipping_shipments` | شحنة = (`order_id`، `connection_id`، `tracking_number`، `idempotency_key` فريد، الحالة الموحدة…) |
| `shipping_events` | سجل تدقيق لكل انتقال حالة |

> ⚠️ قاعدة ذهبية: لا تضع مفاتيح النواقل في `.env` عند تعدد التجار — كل اتصال يحمل مفاتيحه مشفرة في قاعدة البيانات.

## كيف تختار الشركة للتاجر؟ | Carrier eligibility

لا تُستخدم دولة التاجر وحدها. يجمع `CarrierRegistry` (بيانات ثابتة: دول الإرسال والخدمات) مع اتصالات التاجر النشطة وقدرات كل اتصال:

```php
$eligible = app(\Adaa\Shipping\Registry\CarrierRegistry::class)->eligibleForOrigin('EG');
// ['aramex', 'bosta', 'egyptpost', 'fake', 'mylerz'] — ثم تُصفَّى بالاتصالات النشطة والقدرات.
```

## السائقون | Drivers

| الشركة | المنطقة | النوع | create | track | label | return/exchange | COD | الحالة |
|---|---|---|---|---|---|---|---|---|
| Bosta | مصر | مباشر | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mylerz | مصر/MENA | مباشر | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SMSA | السعودية/الخليج | مباشر SOAP (raw-XML) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ جاهز (بدون `ext-soap`) |
| Aramex | MENA/عالمي | مباشر SOAP | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (أصلي: SOAP خام بلا `ext-soap` + أسعار `CalculateRate`) |
| DHL / FedEx / UPS | عالمي | مباشر REST | ✅ | ✅ | ✅ | — | — | مخطط (SDKs ناضجة لكل منها) |
| Egypt Post | مصر | **تتبع فقط** | ❌ | ✅ | — (رابط التتبع العام) | — | — | ✅ تتبع فقط (لا API إنشاء عمومية) |
| Fake | الاختبارات | محلي | ✅ | ✅ | ✅ | — | ✅ | ✅ جاهز |

> إضافة شركة = سائق جديد + `extend()` — دون لمس النواة.

## الاختبارات | Testing

```bash
composer test          # Pest: Unit + Feature
composer run format    # Pint
composer run analyse   # PHPStan
```

تغطي الحزمة: توحيد الحالات، إعلان القدرات، عزل الاتصالات (اتصالان بنفس الشركة بمفتاحين مختلفين)، idempotency، وتوقيع الـ webhooks. اختبارات الـ sandbox الحية موسومة `group=live` وتتطلب مفاتيح حقيقية.

## الحزمة المرافقة | Companion package

- **`adaa/shipping-filament`** — موارد Filament (الاتصالات/الشحنات) للإدارة والتجار. اختيارية تمامًا؛ المكتبة كاملة بدونها.

## خارطة الطريق | Roadmap

- [x] النواة + `FakeDriver` + جسر Laravel + سجل الشركات
- [ ] سائق Bosta + اختباراته
- [x] سائق Aramex (SOAP خام: `CreateShipments`/`TrackShipments`/`PrintLabel`/`CalculateRate`، بلا `ext-soap`)
- [ ] سائقو Mylerz / SMSA
- [ ] DHL / FedEx / UPS + تتبع Egypt Post
- [ ] Jobs + webhooks موقعة + أوامر Artisan للتشخيص
- [ ] حزمة Filament + أول إصدار موسوم `v0.1.0`

## المساهمة | Contributing

المشروع مفتوح المصدر (MIT). الرجاء: اختبار لكل تغيير، وPint قبل أي PR، وعدم إضافة شركة دون اختبارات `Http::fake` + توثيق قدراتها الحقيقية (لا تُعلن عملية غير مدعومة).

## الشكر | Credits

التصميم مستوحى من أفكار [ShipBridge](https://github.com/mohamedhekal/shipbridge) (DTOs، توحيد الحالات، نمط Client/PayloadFactory/Driver) و[v⁠erbb/shippy](https://github.com/verbb/shippy) (تعدد الحسابات الطبيعي، التسعير المقارن).

## الترخيص | License

MIT
