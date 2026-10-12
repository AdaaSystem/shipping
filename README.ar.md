# Adaa Shipping — مكتبة الشحن متعددة الشركات لـ PHP و Laravel

> [English](README.md) | **العربية**

مكتبة شحن PHP متعددة الشركات، نواتها مستقلة عن أي إطار عمل، مع دعم أصيل لـ Laravel — مبنية **لمنصات التجارة متعددة البائعين**: كل متجر يربط **حسابه الخاص** لدى شركة الشحن، إلى جانب **حسابات المنصة**، دون أي تداخل.

## لماذا هذه المكتبة؟

معظم مكتبات الشحن تفترض **حسابًا واحدًا** لكل شركة (مفتاح API في `.env`). هذا يكسر نموذج السوق المفتوح حيث:

- التاجر (أ) لديه عقد Bosta بمفتاحه، والتاجر (ب) لديه عقد Bosta **بمفتاح مختلف**،
- والمنصة نفسها لديها حساب ثالث للشحن المدعوم.

تحل `adaa/shipping` هذا عبر **سياق الاتصال** (`ShippingConnectionContext`): كل عملية شحن تُنفَّذ بسائق مبني **على بيانات اعتماد ذلك الاتصال تحديدًا** — لا إعدادات عمومية، لا مفاتيح مشتركة، لا تسرب بين التجار.

## المزايا

- **نموذج الحسابين:** `platform` (حسابات المنصة) و `merchant` (حسابات التجار) مع عزل كامل.
- **قدرات اختيارية:** كل سائق يعلن ما يدعمه فعلًا (`rates`، `create`، `label`، `track`، `cancel`، `pickup`، `return`، `exchange`، `cod`، `webhooks`…) — لا تُجبر شركة على عمليات وهمية، وشركات التتبع فقط لا تُعرض للإنشاء أبدًا.
- **توحيد الحالات والأخطاء** (`ShipmentStatus` + `StatusNormalizer`) مع الاحتفاظ برد الناقل الخام (`raw`) بعد تنقية الأسرار.
- **نواة مستقلة عن إطار العمل:** تعمل في أي مشروع PHP؛ تكامل Laravel اختياري؛ واجهة Filament في حزمة منفصلة (`adaa/shipping-filament`) — المكتبة تعمل بدونهما طبيعيًا.
- **موثوقية:** مفاتيح idempotency، طوابير، webhooks موقعة لكل اتصال، وسجل تدقيق.

## المتطلبات

- PHP `^8.3`
- `illuminate/contracts` و `illuminate/support` و `illuminate/http` (`^10.0|^11.0|^12.0|^13.0`) — النواة تحتاج فقط عميل HTTP قابلًا للحقن، بلا Facades خارج طبقة Laravel.

## التثبيت

```bash
composer require adaa/shipping
```

## البدء السريع (PHP عادي)

```php
use Adaa\Shipping\Connection\ShippingConnectionContext;
use Adaa\Shipping\DTOs\Address;
use Adaa\Shipping\DTOs\Parcel;
use Adaa\Shipping\DTOs\ShipmentDraft;
use Adaa\Shipping\Enums\ConnectionMode;
use Adaa\Shipping\Enums\Environment;
use Adaa\Shipping\Manager\ShippingManager;

$manager = new ShippingManager();

// سجّل سائقًا: المصنع يستقبل سياق الاتصال فيُبنى السائق بمفاتيحه.
$manager->extend('bosta', fn (ShippingConnectionContext $c) => BostaDriver::fromConnection($http, $c));

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

## داخل Laravel

```bash
php artisan vendor:publish --tag=shipping-config
```

```php
use Adaa\Shipping\Manager\ShippingManager;

$result = app(ShippingManager::class)->driverFor($connection)->createShipment($draft);
```

نموذج البيانات المقترح (انشر migrations الخاصة بك أو استخدم موديلاتك):

| الجدول | الدور |
|---|---|
| `shipping_connections` | اتصال واحد = (`merchant_id` فارغ للمنصة، `carrier_code`، `credentials` مشفرة، `environment`، `status`…) |
| `shipping_shipments` | شحنة = (`order_id`، `connection_id`، `tracking_number`، `idempotency_key` فريد، الحالة الموحدة…) |
| `shipping_events` | سجل تدقيق لكل انتقال حالة |

> ⚠️ قاعدة ذهبية: لا تضع مفاتيح النواقل في `.env` عند تعدد التجار — كل اتصال يحمل مفاتيحه مشفرة في قاعدة البيانات.

## كيف تختار الشركة للتاجر؟

لا تُستخدم دولة التاجر وحدها. يجمع `CarrierRegistry` (بيانات ثابتة: دول الإرسال والخدمات) مع اتصالات التاجر النشطة وقدرات كل اتصال:

```php
$eligible = app(\Adaa\Shipping\Registry\CarrierRegistry::class)->eligibleForOrigin('EG');
// مثل: ['aramex', 'bosta', 'egyptpost', 'fake', 'mylerz'] — ثم تُصفَّى بالاتصالات النشطة والقدرات.
```

## السائقون

| الشركة | المنطقة | النوع | create | track | label | return/exchange | COD | الحالة |
|---|---|---|---|---|---|---|---|---|
| Bosta | مصر | مباشر | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mylerz | مصر/MENA | مباشر | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SMSA | السعودية/الخليج | مباشر SOAP (خام بلا `ext-soap`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Aramex | MENA/عالمي | مباشر SOAP (خام + `CalculateRate`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| DHL Express | عالمي | مباشر REST (MyDHL) | ✅ | ✅ | داخل رد الإنشاء فقط | — | — | ✅ (أسعار + إنشاء + تتبع) |
| FedEx | عالمي | مباشر REST (OAuth2) | ✅ | ✅ | ✅ (بايتات `encodedLabel`) | ✅ | ✅ | ✅ |
| UPS | عالمي | مباشر REST (OAuth2) | ✅ | ✅ | ✅ (بايتات `GraphicImage`) | — | ✅ | ✅ (أسعار Shop + إلغاء void) |
| J&T Express | مصر | مباشر | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Egypt Post | مصر | **تتبع فقط** | ❌ | ✅ | رابط التتبع العام | — | — | ✅ (لا API إنشاء عمومية) |
| Fake | الاختبارات | محلي | ✅ | ✅ | ✅ | — | ✅ | ✅ |

> إضافة شركة = سائق جديد + `extend()` — دون لمس النواة. ويُمنع أن يعلن سائق عملية لا يدعمها.

## الموثوقية

- **Idempotency** — يمنع `idempotency_key` الفريد تكرار الشحنات لدى الناقل عند إعادة المحاولة.
- **الطوابير** — العمليات البطيئة كمهام مع backoff لا يُعيد إنشاء الشحنات.
- **Webhooks** — موقعة لكل اتصال، مع حماية من التكرار وإعادة التشغيل وحماية الحالات النهائية.
- **الأسرار** — مشفرة عند التخزين، منقاة من `raw`، ولا تظهر في السجلات أو الاستثناءات أو ردود API.

## الاختبارات

```bash
composer test          # Pest: Unit + Feature
composer run format    # Pint
composer run analyse   # PHPStan
```

تغطي الحزمة: توحيد الحالات، إعلان القدرات، عزل الاتصالات (اتصالان بنفس الشركة بمفتاحين مختلفين)، idempotency، وتوقيع الـ webhooks. اختبارات الـ sandbox الحية موسومة `group=live` وتتطلب مفاتيح حقيقية.

## الحزمة المرافقة

- **`adaa/shipping-filament`** — موارد Filament (الاتصالات/الشحنات) للإدارة والتجار. اختيارية تمامًا؛ المكتبة كاملة بدونها.

## خارطة الطريق

- [x] النواة + `FakeDriver` + جسر Laravel + سجل الشركات
- [x] سائقو Bosta وMylerz وSMSA وAramex وDHL وFedEx وUPS وJ&T + تتبع Egypt Post
- [ ] مهام الطوابير + webhooks موقعة + أوامر Artisan للتشخيص في جسر Laravel
- [x] حزمة Filament المرافقة + CI + إصدارات آلية (`v0.1.0`)

## الإصدارات والتحديث التلقائي

تصل النسخ الجديدة لكل مشروع تلقائيًا — بلا إعادة تثبيت ولا إعادة تقديم:

1. **المشرف يوسم إصدارًا:** `git tag v0.2.0 && git push --tags`
2. **GitHub Action** (`.github/workflows/release.yml`) ينشئ GitHub Release بملاحظات مولدة تلقائيًا.
3. **Packagist يزامن نفسه** عبر تكامل GitHub (إعداد لمرة واحدة بالأسفل) — تظهر النسخة خلال دقائق.
4. **المشاريع المستهلكة** تحصل عليها بأمر `composer update adaa/shipping` عادي (أو تلقائيًا عبر Dependabot/Renovate).

إعداد Packagist لمرة واحدة (مرة لكل حزمة):

1. قدّم المستودع (أول مرة فقط): <https://packagist.org/packages/submit> — أدخل `https://github.com/AdaaSystem/shipping`.
2. فعّل المزامنة التلقائية: افتح <https://packagist.org/profile> وخوّل **تطبيق Packagist على GitHub** لمنظمة `AdaaSystem`. بعدها يتزامن كل tag دون نقرات (انظر <https://packagist.org/about#how-to-update-packages>).

يعمل CI مع كل push/PR (PHP 8.3–8.5: `composer validate` وPint وPest) ويفتح Dependabot طلبات تحديث أسبوعية.

## المساهمة

المشروع مفتوح المصدر (MIT). الرجاء: اختبار لكل تغيير، وPint قبل أي PR، وعدم إضافة شركة دون اختبارات `Http::fake` + توثيق قدراتها الحقيقية (لا تُعلن عملية غير مدعومة).

## الشكر

التصميم مستوحى من أفكار [ShipBridge](https://github.com/mohamedhekal/shipbridge) (DTOs، توحيد الحالات، نمط Client/PayloadFactory/Driver) و[verbb/shippy](https://github.com/verbb/shippy) (تعدد الحسابات الطبيعي، التسعير المقارن). ودُققت خرائط كل شركة مقابل أنضج SDKs مفتوحة المصدر لكل ناقل.

## الترخيص

MIT
