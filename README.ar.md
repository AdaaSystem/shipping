# Adaa Shipping

> [English](README.md) | **العربية**

شحن متعدد الشركات لـ PHP مع دعم أصيل لـ Laravel. اتصل مرة واحدة لكل **حساب** ناقل — حسابات المنصة الخاصة وحسابات كل تاجر تبقى معزولة تمامًا.

```bash
composer require adaa/shipping
```

## البدء في 60 ثانية (بدون مفاتيح)

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

## المفاهيم الأساسية (اقرأها مرة واحدة)

| الصنف | ما هو |
|---|---|
| `ShippingConnectionContext` | حساب ناقل **واحد**: `connectionId`، `carrierCode`، `mode` (`platform`/`merchant`)، `environment`، `credentials` (في الذاكرة فقط)، `merchantId`، `storeId`، `externalAccountId`. |
| `ShippingManager` | يحلّ السائقين. `extend($code, fn ($ctx) => ...)` يسجّل ناقلًا؛ `driverFor($connection)` يبنيه **من بيانات اعتماد ذلك الاتصال**؛ `shared($code)` يعيد السائقين بلا بيانات اعتماد (fake). |
| `CarrierDriverInterface` | ما ينفذه كل سائق: `capabilities()` مع `createShipment()`. كل ما عداه (`SupportsRates`، `SupportsTracking`، `SupportsLabels`، `SupportsCancellation`) اختياري — تحقق من `capabilities()` أو `instanceof` قبل الاستدعاء. |
| DTOs | `ShipmentDraft` (دخل) ← `ShipmentResult` (`id`، `trackingNumber`، `status`، `labelUrl`، `rate`، `currency`، `raw`) ← `TrackingResult` (`status`، `events[]`) ← `LabelResult` (`contents`، `base64Encoded`، `url`). |

## ربط ناقل حقيقي

سجّل السائق مرة واحدة (مثلًا في service provider)، ممررًا بيانات اعتماد كل اتصال على حدة:

```php
use Adaa\Shipping\Drivers\Bosta\BostaDriver;
use Illuminate\Http\Client\Factory as HttpFactory;

$manager->extend('bosta', fn ($ctx) => BostaDriver::fromConnection(app(HttpFactory::class), $ctx));
```

`fromConnection()` موجودة في **كل** سائق بنفس التوقيع. بيانات الاعتماد تأتي من صف الاتصال لديك (مشفرة في قاعدة بياناتك) — وليس من `.env` أبدًا عندما تخدم عدة تجار.

| الناقل | حقول الاعتماد في `credentials` | من أين تحصل عليها |
|---|---|---|
| Bosta | `api_key`، `base_url` (`https://app.bosta.co`، للتجربة `https://stg-app.bosta.co`) | حساب Business ← تكامل API ([docs](https://docs.bosta.co)) |
| Mylerz | `username`، `password` (تُستبدل تلقائيًا بتوكن) أو `token` ثابت | حساب Business، اطلب الوصول لـ Integration API ([site](https://www.mylerz.com)) |
| SMSA | `passkey` | حساب SECOM متعاقد ([site](https://www.smsaexpress.com)) |
| Aramex | `username`، `password`، `account_number`، `account_pin`، `account_entity`، `account_country_code` | [Developer portal](https://www.aramex.com/developers)، مضيف الاختبار `ws.dev.aramex.net` |
| DHL Express | `username`، `password`، `account_number` (HTTP Basic) | [MyDHL portal](https://developer.dhl.com) |
| FedEx | `client_id`، `client_secret`، `account_number` (OAuth2) | [Developer portal](https://developer.fedex.com)، بيئة التجربة `apis-sandbox.fedex.com` |
| UPS | `client_id`، `client_secret`، `account_number` (OAuth2) | [Developer portal](https://developer.ups.com)، بيئة التجربة `wwwcie.ups.com` |
| J&T Express Egypt | `api_account`، `customer_code`، `private_key`، `customer_pwd` | تأهيل التجار ([site](https://www.jtexpress-eg.com)) |
| MNG Kargo | `client_id`، `client_secret` (تطبيق IBM) + `customer_number`، `password` (اللوحة، JWT) | بوابة ApiZone ([site](https://www.mngkargo.com.tr))، للتجربة `testapi.mngkargo.com.tr` |
| HepsiJet | `username`، `password` (Basic ← `X-Auth-Token`) + `abbreviation_code`، `company_name`، `company_address_id`، `current_xdock_code` | تأهيل التجار، للتجربة `integration-apitest.hepsijet.com` |
| Yurtiçi Kargo | `ws_user_name`، `ws_password` (+ `ws_language`، الافتراضي `TR`) | بيانات WS من الفرع ([site](https://www.yurticikargo.com)) |
| Aras Kargo | `username`، `password` + `customer_code` (تتبع Esasweb؛ `tracking_endpoint` اختياري) | مستخدم SetOrder من الفرع + Esasweb ([site](https://www.araskargo.com.tr)) |
| Sürat Kargo | `kullanici_adi`، `sifre` (+ `web_sifre` للإنشاء) | بيانات SOAP من الفرع، للتجربة `prova.suratkargo.com.tr` ([site](https://www.suratkargo.com.tr)) |
| PTT Kargo | `musteri_id`، `sifre` (+ `kullanici`، الافتراضي `PttWs`؛ `metadata.barcode` مخصص مسبقًا) | خدمات PTT للشركات ([site](https://www.ptt.gov.tr)) |
| Egypt Post | — (تتبع فقط، لا توجد API إنشاء) | — |

مفاتيح اختيارية شائعة يحترمها كل سائق HTTP: تجاوز `base_url` / `endpoint`، و`timeout` (بالثواني)، و`environment` (`sandbox`|`production`).

## مرجع العمليات

كل الأمثلة تفترض `$driver = $manager->driverFor($connection)` وتطلق `Adaa\Shipping\Exceptions\ShippingException` عند فشل الناقل.

### إنشاء شحنة (+ الدفع عند الاستلام)

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

الحقول الإضافية لكل ناقل توضع في `metadata` (انظر `*PayloadFactory` لكل سائق): ‏Bosta ‏`city_code`/`zone`/`district`/`building_number`/`notes`/`webhook_url`؛ ‏J&T ‏`express_type`/`service_type`؛ ‏UPS ‏`service_code` (`11` = standard)؛ ‏FedEx ‏`service_type`؛ ‏Aramex نوع المنتج، وهكذا.

### الأسعار (حيثما مدعومة)

```php
if ($driver instanceof SupportsRates) {
    foreach ($driver->rates($draft, $connection) as $quote) {
        echo "{$quote->serviceName}: {$quote->amount} {$quote->currency}\n";
        // $quote->serviceCode, $quote->estimatedDelivery
    }
}
```

### التتبع

```php
$tracking = $driver->track('BOSTA-TRACKING-NO', $connection);
echo $tracking->status->value; // created|labeled|picked_up|in_transit|out_for_delivery|delivered|exception|cancelled|returned|exchanged
foreach ($tracking->events as $event) {
    echo "{$event->occurredAt} [{$event->location}] {$event->description}\n";
}
```

### الملصقات

```php
$label = $driver->label($shipment->id, $connection, LabelFormat::Pdf);

if ($label->url !== null) {
    // redirect / store the URL
} else {
    file_put_contents('label.pdf', $label->base64Encoded ? base64_decode($label->contents) : $label->contents);
}
```

### الإلغاء

```php
if ($driver instanceof SupportsCancellation) {
    $driver->cancel($shipment->id, $connection); // bool
}
```

### المرتجعات والاستبدال

لا نقاط نهاية منفصلة — الناقلون يمثلونها كأنواع تسليم عند الإنشاء:

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

## حسابان، نفس الناقل (الفكرة كلها)

```php
$platform = new ShippingConnectionContext('conn_platform', 'bosta', ConnectionMode::Platform, Environment::Production, ['api_key' => 'PLATFORM_KEY']);
$merchant = new ShippingConnectionContext('conn_42', 'bosta', ConnectionMode::Merchant, Environment::Production, ['api_key' => 'MERCHANT_KEY'], merchantId: 'm42');

// Different instances, different credentials, zero shared state:
$manager->driverFor($platform)->createShipment($draftA, $platform);
$manager->driverFor($merchant)->createShipment($draftB, $merchant);
```

## Laravel

انشر الإعدادات، ثم احسم السائق لكل طلب من صفوف الاتصال **الخاصة بك** (بيانات الاعتماد مشفرة في قاعدة البيانات):

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

ضع الاستدعاءات البطيئة في طوابير، واربط المهام بـ `connectionId`، ولا تسجّل `$context->credentials` أبدًا. تحقق من webhooks الناقلين حسب سر كل اتصال قبل الوثوق بتحديثات الحالة.

## الحالات والأخطاء

سلاسل الناقلين (`OFD`، `shipped`، …) تُطبَّع إلى `ShipmentStatus`؛ القيم المجهولة تصبح `exception` بدل أن تُطلق استثناءً، لتبقى الـ webhooks صامدة. `ShippingException::carrierFailed($message, $status)` يحمل حالة HTTP؛ و`shipmentNotFound()` تستخدم 404. السائقون ينقّون الأسرار من `raw` قبل إرجاعه — ومع ذلك لا تطبع `raw` في السجلات أبدًا في الإنتاج.

## اختبار تكاملك

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

## السائقون المدعومون

‏Bosta وMylerz وSMSA وAramex وDHL Express وFedEx وUPS وJ&T Express Egypt — إنشاء وتتبع وملصقات كاملة (مع الإلغاء والمرتجع والاستبدال والدفع عند الاستلام حيثما تدعمها API الناقل؛ تحقق من `$driver->capabilities()`)؛ ‏MNG Kargo وYurtiçi Kargo وSürat Kargo وPTT Kargo — إنشاء وتتبع وإلغاء ومرتجع واستبدال ودفع عند الاستلام، دون ملصقات قابلة لإعادة الجلب (ZPL/EPL وقت الإنشاء فقط أو طباعة الباركود)؛ ‏HepsiJet — إنشاء وتتبع وملصقات دون إلغاء؛ ‏Aras Kargo — إنشاء وتتبع وملصقات وإلغاء ومرتجع واستبدال ودفع عند الاستلام؛ ‏Egypt Post تتبع فقط؛ و`FakeDriver` للاختبارات. إضافة ناقل = سائق جديد مع `extend()`، دون لمس النواة.

واجهة الإدارة المرافقة: [`adaa/shipping-filament`](https://github.com/AdaaSystem/shipping-filament) (موارد Filament اختيارية + لوحة تحكم).

## المساهمة

‏MIT. قاعدة واحدة: اختبار لكل تغيير، وPint قبل الـ PRs، ولا تعلن أبدًا عملية لا يدعمها الناقل — تحقق من الـ API الحقيقية ووثّق الدليل.

## الترخيص

‏MIT
