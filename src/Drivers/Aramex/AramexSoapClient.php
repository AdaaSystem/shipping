<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aramex;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use SimpleXMLElement;
use Throwable;

/**
 * Raw SOAP 1.1 client for the Aramex Shipping API V2 — no ext-soap.
 *
 * Envelopes are posted as text/xml over the injectable Illuminate HTTP
 * factory (sandbox → ws.dev.aramex.net, production → ws.aramex.net) and
 * parsed with SimpleXML (core ext only). Every response is reduced to a
 * plain array; SOAP Faults and HasErrors/Notifications blocks become
 * ShippingException::carrierFailed. Credentials never leave the request
 * body and are never copied into responses or exceptions.
 *
 * Operation → service map (official WSDL layout, same as octw/aramex):
 * CreateShipments/PrintLabel → Shipping, TrackShipments → Tracking,
 * CalculateRate → RateCalculator.
 *
 * @phpstan-type AramexNotification array{code: string, message: string}
 */
final class AramexSoapClient
{
    public const NAMESPACE = 'http://ws.aramex.net/shippingapi.v1/';

    public const SANDBOX_HOST = 'https://ws.dev.aramex.net';

    public const PRODUCTION_HOST = 'https://ws.aramex.net';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{has_errors: bool, notifications: list<array{code: string, message: string}>, shipments: list<array{id: string, has_errors: bool, notifications: list<array{code: string, message: string}>, label_url: ?string, label_contents: ?string}>}
     */
    public function createShipments(array $params): array
    {
        $xml = $this->send('CreateShipments', 'Shipping', $this->createShipmentsXml($params));

        $shipments = [];

        foreach ($this->all($xml, 'ProcessedShipment') as $node) {
            $label = $this->first($node, 'ShipmentLabel');
            $shipments[] = [
                'id' => $this->text($node, 'ID') ?? '',
                'has_errors' => $this->boolOf($this->text($node, 'HasErrors')),
                'notifications' => $this->notifications($node),
                'label_url' => $label !== null ? $this->nonEmpty($this->text($label, 'LabelURL')) : null,
                'label_contents' => $label !== null ? $this->nonEmpty($this->text($label, 'LabelFileContents')) : null,
            ];
        }

        return [
            'has_errors' => $this->boolOf($this->firstText($xml, 'HasErrors')),
            'notifications' => $this->notifications($xml),
            'shipments' => $shipments,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{has_errors: bool, notifications: list<array{code: string, message: string}>, updates: list<array{awb: string, code: string, description: string, datetime: ?string, location: ?string}>}
     */
    public function trackShipments(array $params): array
    {
        $xml = $this->send('TrackShipments', 'Tracking', $this->trackShipmentsXml($params));

        $updates = [];

        foreach ($this->all($xml, 'TrackingResult') as $node) {
            $updates[] = [
                'awb' => $this->trackingAwb($node),
                'code' => (string) ($this->text($node, 'UpdateCode') ?? ''),
                'description' => (string) ($this->text($node, 'UpdateDescription') ?? ''),
                'datetime' => $this->nonEmpty($this->text($node, 'UpdateDateTime')),
                'location' => $this->nonEmpty($this->text($node, 'UpdateLocation')),
            ];
        }

        return [
            'has_errors' => $this->boolOf($this->firstText($xml, 'HasErrors')),
            'notifications' => $this->notifications($xml),
            'updates' => $updates,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{has_errors: bool, notifications: list<array{code: string, message: string}>, label_url: ?string, label_contents: ?string}
     */
    public function printLabel(array $params): array
    {
        $xml = $this->send('PrintLabel', 'Shipping', $this->printLabelXml($params));
        $label = $this->descendant($xml, 'ShipmentLabel');

        return [
            'has_errors' => $this->boolOf($this->firstText($xml, 'HasErrors')),
            'notifications' => $this->notifications($xml),
            'label_url' => $label !== null ? $this->nonEmpty($this->text($label, 'LabelURL')) : null,
            'label_contents' => $label !== null ? $this->nonEmpty($this->text($label, 'LabelFileContents')) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{has_errors: bool, notifications: list<array{code: string, message: string}>, total_value: ?float, total_currency: ?string}
     */
    public function calculateRate(array $params): array
    {
        $xml = $this->send('CalculateRate', 'RateCalculator', $this->calculateRateXml($params));
        $total = $this->descendant($xml, 'TotalAmount');
        $value = $total !== null ? $this->text($total, 'Value') : null;

        return [
            'has_errors' => $this->boolOf($this->firstText($xml, 'HasErrors')),
            'notifications' => $this->notifications($xml),
            'total_value' => $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null,
            'total_currency' => $total !== null ? $this->nonEmpty($this->text($total, 'CurrencyCode')) : null,
        ];
    }

    private function send(string $operation, string $service, string $innerXml): SimpleXMLElement
    {
        $this->assertCredentials();

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:arx="'.self::NAMESPACE.'">'
            .'<soap:Body><arx:'.$operation.'>'.$innerXml.'</arx:'.$operation.'></soap:Body></soap:Envelope>';

        try {
            $response = $this->http
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => self::NAMESPACE.'IService_1_0/'.$operation,
                    'X-Requested-By' => 'adaa-shipping-aramex',
                ])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($this->endpoint($service));
        } catch (Throwable $e) {
            throw ShippingException::carrierFailed($this->safeMessage('Aramex transport error: '.$e->getMessage()));
        }

        $body = $response->body();

        if ($body === '') {
            throw ShippingException::carrierFailed('Aramex returned an empty response.', $response->status());
        }

        $xml = $this->parse($body);
        $fault = $this->fault($xml);

        if ($fault !== null) {
            throw ShippingException::carrierFailed($this->safeMessage('Aramex SOAP Fault: '.$fault), $response->status());
        }

        if (! $response->successful()) {
            throw ShippingException::carrierFailed(
                'Aramex request failed (HTTP '.$response->status().').',
                $response->status()
            );
        }

        return $xml;
    }

    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);

            if ($xml === false) {
                throw ShippingException::carrierFailed('Aramex returned an invalid SOAP response.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fault(SimpleXMLElement $xml): ?string
    {
        $faults = $xml->xpath('//*[local-name()="Fault"]');

        if ($faults === null || $faults === []) {
            return null;
        }

        /** @var SimpleXMLElement $fault */
        $fault = $faults[0];
        $message = $this->text($fault, 'faultstring');

        return $message !== null && $message !== '' ? $message : 'Unknown SOAP fault.';
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function notifications(SimpleXMLElement $scope): array
    {
        $found = [];

        foreach ($scope->xpath('.//*[local-name()="Notifications"]/*[local-name()="Notification"]') ?: [] as $node) {
            /** @var SimpleXMLElement $node */
            $found[] = [
                'code' => (string) ($this->text($node, 'Code') ?? ''),
                'message' => (string) ($this->text($node, 'Message') ?? ''),
            ];
        }

        return $found;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function all(SimpleXMLElement $xml, string $name): array
    {
        /** @var list<SimpleXMLElement> $nodes */
        $nodes = $xml->xpath('//*[local-name()="'.$name.'"]') ?: [];

        return $nodes;
    }

    private function first(SimpleXMLElement $scope, string $name): ?SimpleXMLElement
    {
        $nodes = $scope->xpath('./*[local-name()="'.$name.'"]');

        if ($nodes === null || $nodes === [] || ! $nodes[0] instanceof SimpleXMLElement) {
            return null;
        }

        return $nodes[0];
    }

    private function descendant(SimpleXMLElement $scope, string $name): ?SimpleXMLElement
    {
        $nodes = $scope->xpath('.//*[local-name()="'.$name.'"]');

        if ($nodes === null || $nodes === [] || ! $nodes[0] instanceof SimpleXMLElement) {
            return null;
        }

        return $nodes[0];
    }

    private function text(SimpleXMLElement $scope, string $name): ?string
    {
        $node = $this->first($scope, $name);

        if ($node === null) {
            return null;
        }

        return trim((string) $node);
    }

    private function firstText(SimpleXMLElement $xml, string $name): ?string
    {
        $nodes = $xml->xpath('//*[local-name()="'.$name.'"]');

        if ($nodes === null || $nodes === [] || ! $nodes[0] instanceof SimpleXMLElement) {
            return null;
        }

        return trim((string) $nodes[0]);
    }

    private function boolOf(?string $value): bool
    {
        return $value !== null && in_array(strtolower($value), ['1', 'true'], true);
    }

    /**
     * AWB for one TrackingResult: sibling Key of the enclosing pair first
     * (the generated wrapper name carries a random suffix, so climb up),
     * then inline number fields, else empty.
     */
    private function trackingAwb(SimpleXMLElement $node): string
    {
        $keys = $node->xpath('ancestor::*[*[local-name()="Key"] and *[local-name()="Value"]][1]/*[local-name()="Key"]');

        if ($keys !== null && $keys !== [] && $keys[0] instanceof SimpleXMLElement) {
            $key = trim((string) $keys[0]);

            if ($key !== '') {
                return $key;
            }
        }

        return $this->text($node, 'WaybillNumber') ?? $this->text($node, 'ShipmentNumber') ?? '';
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }

    private function endpoint(string $service): string
    {
        $overrides = [
            'Shipping' => 'shipping_endpoint',
            'Tracking' => 'tracking_endpoint',
            'RateCalculator' => 'rate_endpoint',
        ];

        $override = $this->config[$overrides[$service] ?? ''] ?? null;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $environment = $this->config['environment'] ?? $this->config['env'] ?? 'production';

        if ($environment instanceof \BackedEnum) {
            $environment = $environment->value;
        }

        $env = strtolower((string) $environment);
        $host = $env === 'sandbox' || $env === 'test' ? self::SANDBOX_HOST : self::PRODUCTION_HOST;

        return $host.'/ShippingAPI.V2/'.$service.'/Service_1_0.svc';
    }

    private function assertCredentials(): void
    {
        $required = [
            'username' => ['username', 'user_name'],
            'password' => ['password'],
            'account_number' => ['account_number'],
            'account_pin' => ['account_pin'],
            'account_entity' => ['account_entity'],
            'account_country_code' => ['account_country_code', 'account_country'],
        ];

        $missing = [];

        foreach ($required as $label => $keys) {
            $found = false;

            foreach ($keys as $key) {
                if (isset($this->config[$key]) && (string) $this->config[$key] !== '') {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw ShippingException::carrierFailed(
                'Missing Aramex credentials: '.implode(', ', $missing).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createShipmentsXml(array $params): string
    {
        /** @var array<string, mixed> $clientInfo */
        $clientInfo = $params['ClientInfo'] ?? [];
        /** @var array<string, mixed> $transaction */
        $transaction = $params['Transaction'] ?? [];
        /** @var array<string, mixed> $labelInfo */
        $labelInfo = $params['LabelInfo'] ?? [];

        $xml = $this->clientInfoXml($clientInfo)
            .$this->transactionXml($transaction)
            .$this->labelInfoXml($labelInfo)
            .'<arx:Shipments>';

        /** @var list<array<string, mixed>> $shipments */
        $shipments = $params['Shipments'] ?? [];

        foreach ($shipments as $shipment) {
            /** @var array<string, mixed> $details */
            $details = $shipment['Details'] ?? [];

            $xml .= '<arx:Shipment>'
                .$this->partyXml('Shipper', $shipment['Shipper'] ?? [])
                .$this->partyXml('Consignee', $shipment['Consignee'] ?? [])
                .$this->el('ShippingDateTime', $shipment['ShippingDateTime'] ?? null)
                .$this->el('DueDate', $shipment['DueDate'] ?? null)
                .$this->el('Comments', $shipment['Comments'] ?? null)
                .$this->el('PickupLocation', $shipment['PickupLocation'] ?? null)
                .$this->el('Reference1', $shipment['Reference1'] ?? null)
                .'<arx:TransportType>0</arx:TransportType>'
                .'<arx:Details>'
                .$this->amountXml('ActualWeight', $details['ActualWeight'] ?? [], 'KG')
                .$this->el('ProductGroup', $details['ProductGroup'] ?? null)
                .$this->el('ProductType', $details['ProductType'] ?? null)
                .$this->el('PaymentType', $details['PaymentType'] ?? null)
                .$this->opt('PaymentOptions', $details['PaymentOptions'] ?? null)
                .$this->el('NumberOfPieces', $details['NumberOfPieces'] ?? null)
                .$this->el('DescriptionOfGoods', $details['DescriptionOfGoods'] ?? null)
                .$this->el('GoodsOriginCountry', $details['GoodsOriginCountry'] ?? null)
                .$this->opt('Services', $details['Services'] ?? null)
                .$this->moneyXml('CashOnDeliveryAmount', $details['CashOnDeliveryAmount'] ?? [])
                .$this->moneyXml('CollectAmount', $details['CollectAmount'] ?? [])
                .$this->moneyXml('InsuranceAmount', $details['InsuranceAmount'] ?? [])
                .$this->moneyXml('CustomsValueAmount', $details['CustomsValueAmount'] ?? [])
                .'</arx:Details>'
                .'</arx:Shipment>';
        }

        return $xml.'</arx:Shipments>';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function trackShipmentsXml(array $params): string
    {
        /** @var array<string, mixed> $clientInfo */
        $clientInfo = $params['ClientInfo'] ?? [];
        /** @var array<string, mixed> $transaction */
        $transaction = $params['Transaction'] ?? [];

        $xml = $this->clientInfoXml($clientInfo).$this->transactionXml($transaction).'<arx:Shipments>';

        /** @var list<string> $shipments */
        $shipments = $params['Shipments'] ?? [];

        foreach ($shipments as $awb) {
            $xml .= '<arx:string>'.$this->esc($awb).'</arx:string>';
        }

        return $xml.'</arx:Shipments>';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function printLabelXml(array $params): string
    {
        /** @var array<string, mixed> $clientInfo */
        $clientInfo = $params['ClientInfo'] ?? [];
        /** @var array<string, mixed> $transaction */
        $transaction = $params['Transaction'] ?? [];
        /** @var array<string, mixed> $labelInfo */
        $labelInfo = $params['LabelInfo'] ?? [];

        return $this->clientInfoXml($clientInfo)
            .$this->transactionXml($transaction)
            .$this->el('ShipmentNumber', $params['ShipmentNumber'] ?? null)
            .$this->el('ProductGroup', $params['ProductGroup'] ?? null)
            .$this->el('OriginEntity', $params['OriginEntity'] ?? null)
            .$this->labelInfoXml($labelInfo);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function calculateRateXml(array $params): string
    {
        /** @var array<string, mixed> $clientInfo */
        $clientInfo = $params['ClientInfo'] ?? [];
        /** @var array<string, mixed> $transaction */
        $transaction = $params['Transaction'] ?? [];
        /** @var array<string, mixed> $details */
        $details = $params['ShipmentDetails'] ?? [];

        $xml = $this->clientInfoXml($clientInfo)
            .$this->transactionXml($transaction)
            .$this->rateAddressXml('OriginAddress', $params['OriginAddress'] ?? [])
            .$this->rateAddressXml('DestinationAddress', $params['DestinationAddress'] ?? [])
            .'<arx:ShipmentDetails>'
            .$this->el('PaymentType', $details['PaymentType'] ?? null)
            .$this->el('ProductGroup', $details['ProductGroup'] ?? null)
            .$this->el('ProductType', $details['ProductType'] ?? null)
            .$this->amountXml('ActualWeight', $details['ActualWeight'] ?? [], 'KG')
            .$this->el('NumberOfPieces', $details['NumberOfPieces'] ?? null);

        /** @var array<string, mixed>|null $dimensions */
        $dimensions = $details['Dimensions'] ?? null;

        if (is_array($dimensions)) {
            $xml .= '<arx:Dimensions>'
                .$this->el('Length', $dimensions['Length'] ?? null)
                .$this->el('Width', $dimensions['Width'] ?? null)
                .$this->el('Height', $dimensions['Height'] ?? null)
                .$this->el('Unit', $dimensions['Unit'] ?? 'CM')
                .'</arx:Dimensions>';
        }

        return $xml.'</arx:ShipmentDetails>'
            .$this->el('PreferredCurrencyCode', $params['PreferredCurrencyCode'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $clientInfo
     */
    private function clientInfoXml(array $clientInfo): string
    {
        return '<arx:ClientInfo>'
            .$this->el('UserName', $clientInfo['UserName'] ?? null)
            .$this->el('Password', $clientInfo['Password'] ?? null)
            .$this->el('Version', $clientInfo['Version'] ?? 'v1.0')
            .$this->el('AccountNumber', $clientInfo['AccountNumber'] ?? null)
            .$this->el('AccountPin', $clientInfo['AccountPin'] ?? null)
            .$this->el('AccountEntity', $clientInfo['AccountEntity'] ?? null)
            .$this->el('AccountCountryCode', $clientInfo['AccountCountryCode'] ?? null)
            .'</arx:ClientInfo>';
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function transactionXml(array $transaction): string
    {
        return '<arx:Transaction>'
            .$this->el('Reference1', $transaction['Reference1'] ?? '')
            .$this->el('Reference2', $transaction['Reference2'] ?? '')
            .$this->el('Reference3', $transaction['Reference3'] ?? '')
            .$this->el('Reference4', $transaction['Reference4'] ?? '')
            .'</arx:Transaction>';
    }

    /**
     * @param  array<string, mixed>  $labelInfo
     */
    private function labelInfoXml(array $labelInfo): string
    {
        return '<arx:LabelInfo>'
            .$this->el('ReportID', $labelInfo['ReportID'] ?? 9201)
            .$this->el('ReportType', $labelInfo['ReportType'] ?? 'URL')
            .'</arx:LabelInfo>';
    }

    /**
     * @param  array<string, mixed>  $party
     */
    private function partyXml(string $tag, array $party): string
    {
        /** @var array<string, mixed> $contact */
        $contact = $party['Contact'] ?? [];
        /** @var array<string, mixed> $address */
        $address = $party['PartyAddress'] ?? [];

        return '<arx:'.$tag.'>'
            .$this->el('Reference1', $party['Reference1'] ?? '')
            .$this->el('AccountNumber', $party['AccountNumber'] ?? null)
            .'<arx:Contact>'
            .$this->el('PersonName', $contact['PersonName'] ?? null)
            .$this->el('CompanyName', $contact['CompanyName'] ?? null)
            .$this->el('PhoneNumber1', $contact['PhoneNumber1'] ?? null)
            .$this->el('CellPhone', $contact['CellPhone'] ?? null)
            .$this->el('EmailAddress', $contact['EmailAddress'] ?? null)
            .'</arx:Contact>'
            .'<arx:PartyAddress>'
            .$this->el('Line1', $address['Line1'] ?? null)
            .$this->el('Line2', $address['Line2'] ?? '')
            .$this->el('Line3', $address['Line3'] ?? '')
            .$this->el('City', $address['City'] ?? null)
            .$this->el('PostCode', $address['PostCode'] ?? '')
            .$this->el('CountryCode', $address['CountryCode'] ?? null)
            .$this->el('StateOrProvinceCode', $address['StateOrProvinceCode'] ?? '')
            .'</arx:PartyAddress>'
            .'</arx:'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function rateAddressXml(string $tag, array $address): string
    {
        return '<arx:'.$tag.'>'
            .$this->el('Line1', $address['Line1'] ?? null)
            .$this->el('Line2', $address['Line2'] ?? '')
            .$this->el('Line3', $address['Line3'] ?? '')
            .$this->el('City', $address['City'] ?? null)
            .$this->el('StateOrProvinceCode', $address['StateOrProvinceCode'] ?? '')
            .$this->el('PostCode', $address['PostCode'] ?? '')
            .$this->el('CountryCode', $address['CountryCode'] ?? null)
            .'</arx:'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $amount
     */
    private function moneyXml(string $tag, array $amount): string
    {
        return '<arx:'.$tag.'>'
            .$this->el('Value', $amount['Value'] ?? 0)
            .$this->el('CurrencyCode', $amount['CurrencyCode'] ?? null)
            .'</arx:'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $weight
     */
    private function amountXml(string $tag, array $weight, string $unit): string
    {
        return '<arx:'.$tag.'>'
            .$this->el('Value', $weight['Value'] ?? 0)
            .$this->el('Unit', $weight['Unit'] ?? $unit)
            .'</arx:'.$tag.'>';
    }

    private function el(string $tag, mixed $value): string
    {
        return '<arx:'.$tag.'>'.$this->esc($value).'</arx:'.$tag.'>';
    }

    private function opt(string $tag, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->el($tag, $value);
    }

    private function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Scrub credential values out of any string that may reach an exception.
     */
    private function safeMessage(string $message): string
    {
        foreach (['password', 'account_pin', 'account_number', 'username', 'user_name'] as $key) {
            $secret = $this->config[$key] ?? null;

            if (is_string($secret) && $secret !== '' && str_contains($message, $secret)) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
