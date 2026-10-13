<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Aras;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Raw-XML SOAP 1.1 wrapper around the Aras Kargo `arascargoservice.asmx`
 * service — no ext-soap. Envelopes are built as strings and POSTed over the
 * injectable HTTP factory with `Content-Type: text/xml` + `SOAPAction`, then
 * parsed with SimpleXML.
 *
 * Field names below were verified against the live WSDL
 * (`https://customerws.araskargo.com.tr/arascargoservice.asmx?WSDL`,
 * `targetNamespace="http://tempuri.org/"`, `soapAction` =
 * `http://tempuri.org/{Operation}`), not just the reference
 * `mohamedhekal/shipbridge-aras` source. Corrections vs the reference:
 * `SetOrder` takes `orderInfo` → repeated `Order` plus top-level
 * `userName`/`password` (the `Order` type has `CodAmount` /
 * `CodCollectionType` / `CodBillingType` but NO `IsCod` and NO
 * `SenderAccountAddressId` — those belong to the `SetDispatch2`
 * `OrderInfo` type); `GetCargoInfo` takes lowercase `username` plus
 * `customerCode` + `integrationCode`; `CancelDispatch` returns
 * `DispatchResultInfo` (`ResultCode` / `ResultMessage` / `CargoKey`);
 * `GetQueryJSON` is NOT on this WSDL (Esasweb registration per the
 * reference README) so it posts to the configurable `tracking_endpoint`
 * and the driver falls back to `GetCargoInfo`.
 *
 * No facades, no global state, no caching. Credentials never appear in
 * exceptions or in returned `raw` payloads.
 */
final class ArasSoapClient
{
    public const PRODUCTION_ENDPOINT = 'https://customerws.araskargo.com.tr/arascargoservice.asmx';

    public const TEST_ENDPOINT = 'https://customerservicestest.araskargo.com.tr/arascargoservice/arascargoservice.asmx';

    public const NAMESPACE = 'http://tempuri.org/';

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
     * Create via `SetOrder`. Returns the echoed integration code.
     *
     * @param  array<string, mixed>  $order  Single `Order` field map.
     */
    public function setOrder(array $order): string
    {
        $integrationCode = (string) ($order['IntegrationCode'] ?? '');
        $xml = $this->call('SetOrder', $this->setOrderXml($order));

        /** @var list<array{result_code: string, message: string}> $results */
        $results = [];

        foreach ($this->all($xml, 'OrderResultInfo') as $node) {
            $results[] = [
                'result_code' => $this->child($node, ['ResultCode']),
                'message' => $this->child($node, ['ResultMessage']),
            ];
        }

        foreach ($results as $result) {
            if ($result['message'] !== '' && $this->looksLikeError($result['message'])) {
                throw ShippingException::carrierFailed(
                    $this->safeMessage('Aras SetOrder failed: '.$result['message'])
                );
            }
        }

        if ($integrationCode === '') {
            throw ShippingException::carrierFailed('Aras SetOrder did not return an IntegrationCode.');
        }

        return $integrationCode;
    }

    /**
     * Track via `GetQueryJSON` (Esasweb). Returns the decoded JSON payload
     * (usually `HAREKETLER` movements) or an empty array.
     *
     * @return array<string, mixed>|list<mixed>
     */
    public function getQueryJson(string $integrationCode): array
    {
        $xml = $this->call('GetQueryJSON', $this->queryJsonXml($integrationCode), $this->trackingEndpoint());
        $json = $this->scalar($xml, 'GetQueryJSONResult', 'GetQueryJSON', false);

        if ($json !== '' && $json !== '[]') {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Track fallback via `GetCargoInfo` (same endpoint, WSDL-verified).
     *
     * The result is a diffgram dataset whose exact row wrapper varies, so
     * this returns tolerant `rows` (any sub-array carrying a `DURUM`-like
     * key) plus the first status found.
     *
     * @return array{status: string, rows: list<array<string, mixed>>, raw: array<string, mixed>}
     */
    public function getCargoInfo(string $integrationCode): array
    {
        $xml = $this->call('GetCargoInfo', $this->cargoInfoXml($integrationCode));
        $data = $this->nodeToArray($xml);

        $rows = $this->durumRows($data);
        $status = '';

        if (isset($rows[0])) {
            $status = $this->durumOf($rows[0]);
        }

        if ($status === '') {
            $status = $this->firstDurum($data);
        }

        return [
            'status' => $status,
            'rows' => $rows,
            'raw' => ['status' => $status, 'rows' => $rows],
        ];
    }

    /**
     * Cancel via `CancelDispatch`. Returns the result message.
     */
    public function cancelDispatch(string $integrationCode): string
    {
        $xml = $this->call('CancelDispatch', $this->flatXml([
            'userName' => $this->username(),
            'password' => $this->password(),
            'integrationCode' => $integrationCode,
        ]));

        $message = $this->child($this->firstNode($xml, 'DispatchResultInfo') ?? $xml, ['ResultMessage']);

        if ($message === '') {
            $message = $this->scalar($xml, 'CancelDispatchResult', 'CancelDispatch', false);
        }

        if ($message !== '' && $this->looksLikeError($message)) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Aras CancelDispatch failed: '.$message)
            );
        }

        $cargoKey = $this->child($this->firstNode($xml, 'DispatchResultInfo') ?? $xml, ['CargoKey']);

        return $message !== '' ? $message : ($cargoKey !== '' ? $cargoKey : 'OK');
    }

    /**
     * Label bytes via WSDL-verified `GetArasBarcode` (`Username` /
     * `Password` / `integrationCode` → `ZebraPdf` strings, `Images`
     * base64 fallbacks).
     */
    public function getArasBarcode(string $integrationCode): string
    {
        $xml = $this->call('GetArasBarcode', $this->flatXml([
            'Username' => $this->username(),
            'Password' => $this->password(),
            'integrationCode' => $integrationCode,
        ]));

        foreach (['ZebraPdf', 'Images'] as $container) {
            foreach ($this->all($xml, $container) as $node) {
                foreach (['string', 'base64Binary'] as $item) {
                    $value = $this->child($node, [$item]);

                    if ($value !== '') {
                        return $value;
                    }
                }

                $direct = trim((string) $node);

                if ($direct !== '' && strlen($direct) > 64) {
                    return $direct;
                }
            }
        }

        $message = $this->firstScalar($xml, 'Message');

        throw ShippingException::carrierFailed(
            $message !== '' ? $this->safeMessage('Aras GetArasBarcode failed: '.$message) : 'Aras GetArasBarcode returned an empty label.'
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function setOrderXml(array $order): string
    {
        $inner = '';

        foreach ($order as $key => $value) {
            $tag = (string) $key;

            if ($tag === '' || $value === null) {
                continue;
            }

            $inner .= '<'.$tag.'>'.$this->escape((string) $value).'</'.$tag.'>';
        }

        return '<orderInfo><Order>'.$inner.'</Order></orderInfo>'
            .'<userName>'.$this->escape($this->username()).'</userName>'
            .'<password>'.$this->escape($this->password()).'</password>';
    }

    private function queryJsonXml(string $integrationCode): string
    {
        return '<queryInfo>'
            .'<QueryType>1</QueryType>'
            .'<CustomerCode>'.$this->escape($this->customerCode()).'</CustomerCode>'
            .'<IntegrationCode>'.$this->escape($integrationCode).'</IntegrationCode>'
            .'</queryInfo>'
            .'<userName>'.$this->escape($this->username()).'</userName>'
            .'<password>'.$this->escape($this->password()).'</password>';
    }

    private function cargoInfoXml(string $integrationCode): string
    {
        return $this->flatXml([
            'username' => $this->username(),
            'password' => $this->password(),
            'customerCode' => $this->customerCode(),
            'integrationCode' => $integrationCode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function flatXml(array $params): string
    {
        $inner = '';

        foreach ($params as $key => $value) {
            $tag = (string) $key;

            if ($tag === '') {
                continue;
            }

            $inner .= '<'.$tag.'>'.$this->escape((string) $value).'</'.$tag.'>';
        }

        return $inner;
    }

    private function call(string $operation, string $innerXml, ?string $endpoint = null): SimpleXMLElement
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<'.$operation.' xmlns="'.self::NAMESPACE.'">'
            .$innerXml
            .'</'.$operation.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';

        $timeout = (int) ($this->config['timeout'] ?? 45);

        /** @var Response $response */
        $response = $this->http
            ->timeout($timeout > 0 ? $timeout : 45)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => self::NAMESPACE.$operation,
                'X-Requested-By' => 'adaa-shipping-aras',
            ])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($endpoint ?? $this->endpoint());

        $body = $response->body();

        if (! is_string($body)) {
            $body = '';
        }

        if ($response->successful()) {
            return $this->parse($body, $operation);
        }

        $faultMessage = $this->faultMessage($body);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Aras '.$operation.' SOAP Fault: '.$faultMessage),
                $response->status(),
            );
        }

        $suffix = $body !== '' && strlen($body) < 300 ? ': '.$this->redact($body) : '.';

        throw ShippingException::carrierFailed(
            'Aras '.$operation.' failed (HTTP '.$response->status().')'.$suffix,
            $response->status(),
        );
    }

    private function parse(string $body, string $operation): SimpleXMLElement
    {
        if ($body === '') {
            throw ShippingException::carrierFailed('Aras '.$operation.' returned an empty response.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();

        if ($xml === false) {
            throw ShippingException::carrierFailed('Aras '.$operation.' returned invalid XML.');
        }

        $faultMessage = $this->faultMessage($body, $xml);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Aras '.$operation.' SOAP Fault: '.$faultMessage)
            );
        }

        return $xml;
    }

    private function scalar(SimpleXMLElement $xml, string $node, string $operation, bool $required = true): string
    {
        $found = $xml->xpath('//*[local-name()="'.$node.'"]');

        if (! is_array($found) || ! isset($found[0])) {
            if ($required) {
                throw ShippingException::carrierFailed('Aras '.$operation.' response was missing '.$node.'.');
            }

            return '';
        }

        return trim((string) $found[0]);
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

    private function firstNode(SimpleXMLElement $xml, string $name): ?SimpleXMLElement
    {
        $nodes = $xml->xpath('//*[local-name()="'.$name.'"]');

        if (is_array($nodes) && isset($nodes[0]) && $nodes[0] instanceof SimpleXMLElement) {
            return $nodes[0];
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     */
    private function child(SimpleXMLElement $node, array $names): string
    {
        foreach ($names as $name) {
            $found = $node->xpath('./*[local-name()="'.$name.'"]');

            if (is_array($found) && isset($found[0])) {
                $value = trim((string) $found[0]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function durumRows(array $data): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        $this->collectDurumRows($data, $rows);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectDurumRows(array $data, array &$rows): void
    {
        if ($this->durumOf($data) !== '' && (isset($data['DURUM']) || isset($data['Durum']) || isset($data['durum']) || isset($data['status']))) {
            $rows[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->collectDurumRows($item, $rows);
                        }
                    }
                } else {
                    $this->collectDurumRows($value, $rows);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function durumOf(array $row): string
    {
        foreach (['DURUM', 'Durum', 'durum', 'status', 'Status'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function firstDurum(array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, ['DURUM', 'Durum', 'durum', 'status', 'Status'], true) && is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $found = $this->firstDurum(array_is_list($value) ? array_filter($value, 'is_array') : $value);

                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    /**
     * @param  string|list<string>  $names
     */
    private function firstScalar(SimpleXMLElement $xml, string|array $names): string
    {
        foreach ((array) $names as $name) {
            $found = $xml->xpath('//*[local-name()="'.$name.'"]');

            if (is_array($found) && isset($found[0])) {
                $value = trim((string) $found[0]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Convert a response node to an array keyed by local element names.
     *
     * Uses DOM (not `json_encode`, which drops namespace-qualified
     * children) so prefixed (`ns:`) and default-namespace documents parse
     * identically. Repeated siblings become lists.
     *
     * @return array<string, mixed>
     */
    private function nodeToArray(SimpleXMLElement $node): array
    {
        $dom = dom_import_simplexml($node);

        if ($dom === false) {
            return [];
        }

        $data = $this->elementToArray($dom);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function elementToArray(\DOMElement $element): array|string
    {
        /** @var array<string, mixed> $data */
        $data = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $name = $child->localName !== null && $child->localName !== '' ? $child->localName : $child->nodeName;
            $value = $this->elementToArray($child);

            if (array_key_exists($name, $data)) {
                if (! is_array($data[$name]) || ! array_is_list($data[$name])) {
                    $data[$name] = [$data[$name]];
                }

                $data[$name][] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        if ($data === []) {
            return trim($element->textContent);
        }

        return $data;
    }

    private function faultMessage(string $body, ?SimpleXMLElement $xml = null): string
    {
        if ($xml === null) {
            libxml_use_internal_errors(true);

            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

            libxml_clear_errors();

            if ($xml === false) {
                return '';
            }
        }

        $faults = $xml->xpath('//*[local-name()="Fault"]');

        if (! is_array($faults) || $faults === []) {
            return '';
        }

        $strings = $xml->xpath('//*[local-name()="faultstring"]');

        if (is_array($strings) && isset($strings[0])) {
            $message = trim((string) $strings[0]);

            if ($message !== '') {
                return $message;
            }
        }

        return 'SOAP Fault';
    }

    private function endpoint(): string
    {
        $override = $this->config['endpoint']
            ?? $this->config['base_url']
            ?? $this->config['wsdl']
            ?? null;

        if (is_string($override) && $override !== '') {
            return rtrim(explode('?', $override, 2)[0], '/');
        }

        $environment = $this->config['environment'] ?? 'production';

        if ($environment instanceof \BackedEnum) {
            $environment = $environment->value;
        }

        if (strtolower((string) $environment) === 'sandbox') {
            return self::TEST_ENDPOINT;
        }

        return self::PRODUCTION_ENDPOINT;
    }

    private function trackingEndpoint(): string
    {
        $override = $this->config['tracking_endpoint']
            ?? $this->config['tracking_wsdl']
            ?? null;

        if (is_string($override) && $override !== '') {
            return rtrim(explode('?', $override, 2)[0], '/');
        }

        return $this->endpoint();
    }

    private function username(): string
    {
        $value = $this->config['username'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing Aras username (per-connection credentials).');
        }

        return $value;
    }

    private function password(): string
    {
        $value = $this->config['password'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing Aras password (per-connection credentials).');
        }

        return $value;
    }

    private function customerCode(): string
    {
        $value = $this->config['customer_code'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing Aras customer_code (per-connection credentials).');
        }

        return $value;
    }

    private function looksLikeError(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $lower = mb_strtolower($value, 'UTF-8');

        return str_contains($lower, 'error')
            || str_contains($lower, 'failed')
            || str_contains($lower, 'hata')
            || str_contains($lower, 'invalid')
            || str_contains($lower, 'hatalı');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function redact(string $value): string
    {
        foreach (['username', 'password'] as $key) {
            $secret = $this->config[$key] ?? null;

            if (is_string($secret) && $secret !== '' && str_contains($value, $secret)) {
                $value = str_replace($secret, '***', $value);
            }
        }

        return $value;
    }

    private function safeMessage(string $message): string
    {
        return $this->redact($message);
    }
}
