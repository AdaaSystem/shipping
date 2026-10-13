<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Yurtici;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Raw-XML SOAP 1.1 wrapper around the Yurtiçi Kargo Giden Kargo
 * `ShippingOrderDispatcherServices` — no ext-soap. Envelopes are built as
 * strings and POSTed over the injectable HTTP factory with
 * `Content-Type: text/xml` + `SOAPAction`, then parsed with SimpleXML.
 *
 * Shapes are cross-verified between the reference
 * `mohamedhekal/shipbridge-yurtici` source (op names `createShipment` /
 * `queryShipment`, `wsUserName` / `wsPassword` auth with `userLanguage` on
 * create but `wsLanguage` on query, `outFlag === '0'` success,
 * `ShippingOrderVO` / `ShippingDeliveryVO` result VOs) and the independent
 * `tcgunel/omniship-yurtici` source. Corrections vs the shipbridge
 * reference: cancel posts to `cancelShipment` (omniship-verified) rather
 * than `cancelDispatch`; `ShippingOrderVO` is always sent as a repeated
 * list element even for one parcel; `desi`/`kg` are omitted when unknown
 * (an empty element is not a valid `xs:double`); `taxOfficeId`,
 * `ttDocumentId`, `dcSelectedCredit`, `dcCreditRule` are numeric (0 when
 * unset); `taxNumber` is only sent as 10–11 digits. Namespace default
 * `http://yurticikargo.com/` comes from the WSDL `targetNamespace`
 * (WSDL itself unreachable from here — override via config `namespace`).
 *
 * No facades, no global state, no caching. Credentials never appear in
 * exceptions or in returned `raw` payloads.
 */
final class YurticiSoapClient
{
    public const PRODUCTION_ENDPOINT = 'https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';

    public const TEST_ENDPOINT = 'https://testws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';

    public const NAMESPACE = 'http://yurticikargo.com/';

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
     * Create via `createShipment`.
     *
     * @param  array<string, mixed>  $params  Flat auth + `ShippingOrderVO` list.
     * @return array{out_flag: string, out_result: string, job_id: string, detail: array<string, mixed>, raw: array<string, mixed>}
     */
    public function createShipment(array $params): array
    {
        $xml = $this->call('createShipment', $this->shipmentXml($params));
        $result = $this->firstNode($xml, 'ShippingOrderResultVO');

        if ($result === null) {
            throw ShippingException::carrierFailed('Yurtiçi Kargo createShipment response was missing ShippingOrderResultVO.');
        }

        $detailNode = $this->firstChild($result, 'shippingOrderDetailVO');

        /** @var array<string, mixed> $detail */
        $detail = $detailNode !== null ? $this->nodeToArray($detailNode) : [];

        return [
            'out_flag' => $this->child($result, ['outFlag']),
            'out_result' => $this->child($result, ['outResult']),
            'job_id' => $this->child($result, ['jobId']),
            'detail' => $detail,
            'raw' => [
                'out_flag' => $this->child($result, ['outFlag']),
                'out_result' => $this->child($result, ['outResult']),
                'job_id' => $this->child($result, ['jobId']),
                'detail' => $detail,
            ],
        ];
    }

    /**
     * Track via `queryShipment`.
     *
     * @param  array<string, mixed>  $params  Flat auth + `keys` list.
     * @return array{out_flag: string, out_result: string, detail: array<string, mixed>, raw: array<string, mixed>}
     */
    public function queryShipment(array $params): array
    {
        $xml = $this->call('queryShipment', $this->queryXml($params));
        $result = $this->firstNode($xml, 'ShippingDeliveryVO');

        if ($result === null) {
            throw ShippingException::carrierFailed('Yurtiçi Kargo queryShipment response was missing ShippingDeliveryVO.');
        }

        $detailNode = $this->firstChild($result, 'shippingDeliveryDetailVO');

        /** @var array<string, mixed> $detail */
        $detail = $detailNode !== null ? $this->nodeToArray($detailNode) : [];

        return [
            'out_flag' => $this->child($result, ['outFlag']),
            'out_result' => $this->child($result, ['outResult']),
            'detail' => $detail,
            'raw' => [
                'out_flag' => $this->child($result, ['outFlag']),
                'out_result' => $this->child($result, ['outResult']),
                'detail' => $detail,
            ],
        ];
    }

    /**
     * Cancel via `cancelShipment` (omniship-verified op name).
     *
     * @param  array<string, mixed>  $params  Flat auth + `cargoKeys` list.
     * @return array{operation_status: string, operation_code: string, operation_message: string, raw: array<string, mixed>}
     */
    public function cancelShipment(array $params): array
    {
        $xml = $this->call($this->config['cancel_operation'] ?? 'cancelShipment', $this->cancelXml($params));

        $status = $this->firstScalar($xml, 'operationStatus');
        $code = $this->firstScalar($xml, 'operationCode');
        $message = $this->firstScalar($xml, ['operationMessage', 'outResult']);

        return [
            'operation_status' => $status !== '' ? $status : $code,
            'operation_code' => $code,
            'operation_message' => $message,
            'raw' => ['operation_status' => $status, 'operation_code' => $code, 'operation_message' => $message],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function shipmentXml(array $params): string
    {
        $xml = $this->authXml($params, false);

        /** @var list<array<string, mixed>> $orders */
        $orders = $params['ShippingOrderVO'] ?? [];

        foreach ($orders as $order) {
            $xml .= '<ShippingOrderVO>'.$this->mapXml(is_array($order) ? $order : []).'</ShippingOrderVO>';
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function queryXml(array $params): string
    {
        $xml = $this->authXml($params, true);

        foreach ((array) ($params['keys'] ?? []) as $key) {
            $xml .= '<keys>'.$this->escape((string) $key).'</keys>';
        }

        $xml .= '<keyType>'.(int) ($params['keyType'] ?? 0).'</keyType>'
            .'<addHistoricalData>'.$this->bool($params['addHistoricalData'] ?? true).'</addHistoricalData>'
            .'<onlyTracking>'.$this->bool($params['onlyTracking'] ?? false).'</onlyTracking>';

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function cancelXml(array $params): string
    {
        $xml = $this->authXml($params, false);

        foreach ((array) ($params['cargoKeys'] ?? []) as $key) {
            $xml .= '<cargoKeys>'.$this->escape((string) $key).'</cargoKeys>';
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function authXml(array $params, bool $query): string
    {
        $languageKey = $query ? 'wsLanguage' : 'userLanguage';

        return '<wsUserName>'.$this->escape((string) ($params['wsUserName'] ?? '')).'</wsUserName>'
            .'<wsPassword>'.$this->escape((string) ($params['wsPassword'] ?? '')).'</wsPassword>'
            .'<'.$languageKey.'>'.$this->escape((string) ($params[$languageKey] ?? $params['userLanguage'] ?? 'TR')).'</'.$languageKey.'>';
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function mapXml(array $map): string
    {
        $xml = '';

        foreach ($map as $key => $value) {
            $tag = (string) $key;

            if ($tag === '' || $value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $xml .= '<'.$tag.'>'.$this->bool($value).'</'.$tag.'>';

                continue;
            }

            if (is_array($value)) {
                $xml .= '<'.$tag.'>'.$this->mapXml($value).'</'.$tag.'>';

                continue;
            }

            $xml .= '<'.$tag.'>'.$this->escape((string) $value).'</'.$tag.'>';
        }

        return $xml;
    }

    private function call(string $operation, string $innerXml): SimpleXMLElement
    {
        $namespace = (string) ($this->config['namespace'] ?? self::NAMESPACE);

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<'.$operation.' xmlns="'.$namespace.'">'
            .$innerXml
            .'</'.$operation.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';

        $soapAction = $this->config['soap_action'] ?? ($namespace.$operation);
        $timeout = (int) ($this->config['timeout'] ?? 45);

        /** @var Response $response */
        $response = $this->http
            ->timeout($timeout > 0 ? $timeout : 45)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => is_string($soapAction) ? $soapAction : $namespace.$operation,
                'X-Requested-By' => 'adaa-shipping-yurtici',
            ])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($this->endpoint());

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
                $this->safeMessage('Yurtiçi Kargo '.$operation.' SOAP Fault: '.$faultMessage),
                $response->status(),
            );
        }

        $suffix = $body !== '' && strlen($body) < 300 ? ': '.$this->redact($body) : '.';

        throw ShippingException::carrierFailed(
            'Yurtiçi Kargo '.$operation.' failed (HTTP '.$response->status().')'.$suffix,
            $response->status(),
        );
    }

    private function parse(string $body, string $operation): SimpleXMLElement
    {
        if ($body === '') {
            throw ShippingException::carrierFailed('Yurtiçi Kargo '.$operation.' returned an empty response.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();

        if ($xml === false) {
            throw ShippingException::carrierFailed('Yurtiçi Kargo '.$operation.' returned invalid XML.');
        }

        $faultMessage = $this->faultMessage($body, $xml);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Yurtiçi Kargo '.$operation.' SOAP Fault: '.$faultMessage)
            );
        }

        return $xml;
    }

    private function firstNode(SimpleXMLElement $xml, string $name): ?SimpleXMLElement
    {
        $nodes = $xml->xpath('//*[local-name()="'.$name.'"]');

        if (is_array($nodes) && isset($nodes[0]) && $nodes[0] instanceof SimpleXMLElement) {
            return $nodes[0];
        }

        return null;
    }

    private function firstChild(SimpleXMLElement $node, string $name): ?SimpleXMLElement
    {
        $nodes = $node->xpath('./*[local-name()="'.$name.'"]');

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
     * Convert one response detail node (plus its movement rows) to an array.
     *
     * Uses DOM (not `json_encode`, which drops namespace-qualified
     * children) so namespaced and plain documents parse identically.
     * Repeated siblings become lists.
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

    private function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function redact(string $value): string
    {
        foreach (['ws_password', 'wsPassword', 'ws_user_name', 'wsUserName'] as $key) {
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
