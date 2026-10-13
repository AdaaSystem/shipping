<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Ptt;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Raw-XML SOAP 1.1 wrapper around the two PTT Kargo Axis2 services — no
 * ext-soap. Envelopes are built as strings and POSTed over the injectable
 * HTTP factory with `Content-Type: text/xml` + `SOAPAction`, then parsed
 * with SimpleXML.
 *
 * Operation + field names were verified against the live WSDLs (upload:
 * `https://pttws.ptt.gov.tr/PttVeriYukleme/services/Sorgu?wsdl`,
 * namespace `http://kabul.ptt.gov.tr`; tracking:
 * `https://pttws.ptt.gov.tr/GonderiHareketV2/services/Sorgu?wsdl`,
 * namespace `http://hareket.ptt.gov.tr`; both document/literal with
 * `soapAction="urn:{operation}"`), not just the reference
 * `mohamedhekal/shipbridge-ptt` source. Corrections vs the reference:
 * `kabulEkle2` takes `input` directly containing repeated `dongu` rows
 * (there is NO `kabulEkle2Input` wrapper); the per-row result field is
 * `barkod` (not `barkodNo`); tracking is `barkodSorgu` with
 * `barkod`/`kullanici`/`musteri_no`/`sifre` (there is NO `gonderiDurum`
 * operation on either service); cancel is `barkodVeriSil` with
 * `barcode`/`dosyaAdi`/`musteriId`/`sifre` (there is NO `gonderiIptal`
 * operation). Because both services are Axis2 with
 * `elementFormDefault="qualified"`, every element is namespace-qualified.
 *
 * No facades, no global state, no caching. Credentials never appear in
 * exceptions or in returned `raw` payloads.
 */
final class PttSoapClient
{
    public const UPLOAD_ENDPOINT = 'https://pttws.ptt.gov.tr/PttVeriYukleme/services/Sorgu';

    public const TRACKING_ENDPOINT = 'https://pttws.ptt.gov.tr/GonderiHareketV2/services/Sorgu';

    public const UPLOAD_NAMESPACE = 'http://kabul.ptt.gov.tr';

    public const TRACKING_NAMESPACE = 'http://hareket.ptt.gov.tr';

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
     * Upload via `kabulEkle2`. Returns the accepted barcode.
     *
     * @param  array<string, mixed>  $input  `input` field map with `dongu` rows.
     */
    public function kabulEkle2(array $input): string
    {
        $xml = $this->call('kabulEkle2', $this->uploadInputXml($input), $this->uploadEndpoint(), self::UPLOAD_NAMESPACE);
        $result = $this->firstNode($xml, 'return');

        if ($result === null) {
            throw ShippingException::carrierFailed('PTT kabulEkle2 response was missing return.');
        }

        $hataKodu = (int) $this->child($result, ['hataKodu']);

        if ($hataKodu !== 1) {
            $message = $this->child($result, ['aciklama']);

            throw ShippingException::carrierFailed(
                $this->safeMessage('PTT kabulEkle2 failed: '.($message !== '' ? $message : 'hataKodu '.$hataKodu))
            );
        }

        $row = $this->firstChild($result, 'dongu');

        if ($row !== null) {
            $rowHata = (int) $this->child($row, ['donguHataKodu']);
            $rowSonuc = strtolower($this->child($row, ['donguSonuc']));
            $rowMessage = $this->child($row, ['donguAciklama']);

            if (($rowHata !== 0 && $rowHata !== 1) || in_array($rowSonuc, ['false', '0'], true)) {
                throw ShippingException::carrierFailed(
                    $this->safeMessage('PTT kabulEkle2 row failed: '.($rowMessage !== '' ? $rowMessage : 'donguHataKodu '.$rowHata))
                );
            }

            $barcode = $this->child($row, ['barkod']);

            if ($barcode !== '') {
                return $barcode;
            }
        }

        throw ShippingException::carrierFailed('PTT kabulEkle2 did not return a barkod.');
    }

    /**
     * Detail query via `barkodSorgu`.
     *
     * @return array{status: string, detail: array<string, mixed>, raw: array<string, mixed>}
     */
    public function barkodSorgu(string $barcode): array
    {
        $xml = $this->call('barkodSorgu', $this->trackingInputXml($barcode), $this->trackingEndpoint(), self::TRACKING_NAMESPACE);
        $result = $this->firstNode($xml, 'return');

        if ($result === null) {
            throw ShippingException::carrierFailed('PTT barkodSorgu response was missing return.');
        }

        /** @var array<string, mixed> $detail */
        $detail = $this->nodeToArray($result);
        $status = (string) ($detail['sonIslemAciklama'] ?? $detail['gonderiDurumAciklama'] ?? '');

        return [
            'status' => $status,
            'detail' => $detail,
            'raw' => ['detail' => $detail],
        ];
    }

    /**
     * Cancel via WSDL-verified `barkodVeriSil`. The `dosyaAdi` is the upload
     * file name returned at create time (preserved in `raw.dosya_adi`).
     */
    public function barkodVeriSil(string $barcode, string $dosyaAdi): string
    {
        $namespace = self::UPLOAD_NAMESPACE;

        $xml = $this->call('barkodVeriSil', $this->qualifiedMap($namespace, [
            'barcode' => $barcode,
            'dosyaAdi' => $dosyaAdi,
            'musteriId' => $this->musteriId(),
            'sifre' => $this->sifre(),
        ]), $this->uploadEndpoint(), $namespace);

        $result = $this->firstNode($xml, 'return');

        if ($result === null) {
            throw ShippingException::carrierFailed('PTT barkodVeriSil response was missing return.');
        }

        $hataKodu = (int) $this->child($result, ['hataKodu']);

        if ($hataKodu !== 1) {
            $message = $this->child($result, ['aciklama']);

            throw ShippingException::carrierFailed(
                $this->safeMessage('PTT barkodVeriSil failed: '.($message !== '' ? $message : 'hataKodu '.$hataKodu))
            );
        }

        $message = $this->child($result, ['aciklama']);

        return $message !== '' ? $message : 'OK';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function uploadInputXml(array $input): string
    {
        $namespace = self::UPLOAD_NAMESPACE;

        /** @var list<array<string, mixed>> $rows */
        $rows = $input['dongu'] ?? [];
        $xml = '';

        foreach ($rows as $row) {
            $xml .= '<ns:dongu>'.$this->qualifiedMap($namespace, is_array($row) ? $row : []).'</ns:dongu>';
        }

        foreach (['dosyaAdi', 'gonderiTip', 'gonderiTur', 'kullanici', 'musteriId', 'sifre'] as $field) {
            if (isset($input[$field]) && (string) $input[$field] !== '') {
                $xml .= '<ns:'.$field.'>'.$this->escape((string) $input[$field]).'</ns:'.$field.'>';
            }
        }

        return '<ns:input>'.$xml.'</ns:input>';
    }

    private function trackingInputXml(string $barcode): string
    {
        $namespace = self::TRACKING_NAMESPACE;

        return '<ns:input>'.$this->qualifiedMap($namespace, [
            'barkod' => $barcode,
            'kullanici' => $this->kullanici(),
            'musteri_no' => $this->musteriId(),
            'sifre' => $this->sifre(),
        ]).'</ns:input>';
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function qualifiedMap(string $namespace, array $map): string
    {
        $xml = '';

        foreach ($map as $key => $value) {
            $tag = (string) $key;

            if ($tag === '' || $value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $xml .= '<ns:'.$tag.'>'.$this->qualifiedMap($namespace, $value).'</ns:'.$tag.'>';

                continue;
            }

            $xml .= '<ns:'.$tag.'>'.$this->escape((string) $value).'</ns:'.$tag.'>';
        }

        return $xml;
    }

    private function call(string $operation, string $innerXml, string $endpoint, string $namespace): SimpleXMLElement
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:ns="'.$namespace.'">'
            .'<soap:Body>'
            .'<ns:'.$operation.'>'
            .$innerXml
            .'</ns:'.$operation.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';

        $timeout = (int) ($this->config['timeout'] ?? 45);

        /** @var Response $response */
        $response = $this->http
            ->timeout($timeout > 0 ? $timeout : 45)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => 'urn:'.$operation,
                'X-Requested-By' => 'adaa-shipping-ptt',
            ])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($endpoint);

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
                $this->safeMessage('PTT '.$operation.' SOAP Fault: '.$faultMessage),
                $response->status(),
            );
        }

        $suffix = $body !== '' && strlen($body) < 300 ? ': '.$this->redact($body) : '.';

        throw ShippingException::carrierFailed(
            'PTT '.$operation.' failed (HTTP '.$response->status().')'.$suffix,
            $response->status(),
        );
    }

    private function parse(string $body, string $operation): SimpleXMLElement
    {
        if ($body === '') {
            throw ShippingException::carrierFailed('PTT '.$operation.' returned an empty response.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();

        if ($xml === false) {
            throw ShippingException::carrierFailed('PTT '.$operation.' returned invalid XML.');
        }

        $faultMessage = $this->faultMessage($body, $xml);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('PTT '.$operation.' SOAP Fault: '.$faultMessage)
            );
        }

        // Axis2 business faults arrive inline as SorguException/Message.
        $inlineFault = $this->firstScalar($xml, ['Message']);

        if ($inlineFault !== '' && $this->firstNode($xml, 'SorguException') !== null) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('PTT '.$operation.' failed: '.$inlineFault)
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
     * Convert a response node to an array keyed by local element names.
     *
     * Uses DOM (not `json_encode`, which drops namespace-qualified
     * children) so the Axis2 `ns:`-prefixed documents parse correctly.
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

    private function uploadEndpoint(): string
    {
        $override = $this->config['upload_endpoint']
            ?? $this->config['endpoint']
            ?? $this->config['upload_wsdl']
            ?? null;

        if (is_string($override) && $override !== '') {
            return rtrim(explode('?', $override, 2)[0], '/');
        }

        $wsdl = (string) ($this->config['upload_wsdl'] ?? self::UPLOAD_ENDPOINT);

        return rtrim(explode('?', $this->applyTestSuffix($wsdl, 'PttVeriYukleme'), 2)[0], '/');
    }

    private function trackingEndpoint(): string
    {
        $override = $this->config['tracking_endpoint']
            ?? $this->config['tracking_wsdl']
            ?? null;

        if (is_string($override) && $override !== '') {
            return rtrim(explode('?', $override, 2)[0], '/');
        }

        $wsdl = (string) ($this->config['tracking_wsdl'] ?? self::TRACKING_ENDPOINT);

        return rtrim(explode('?', $this->applyTestSuffix($wsdl, 'GonderiHareketV2'), 2)[0], '/');
    }

    private function applyTestSuffix(string $wsdl, string $service): string
    {
        if (! (bool) ($this->config['test_mode'] ?? false)) {
            return $wsdl;
        }

        return (string) preg_replace(
            '/\/'.preg_quote($service, '/').'\//',
            '/'.$service.'Test/',
            $wsdl,
        );
    }

    private function musteriId(): string
    {
        $value = $this->config['musteri_id'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing PTT musteri_id (per-connection credentials).');
        }

        return $value;
    }

    private function sifre(): string
    {
        $value = $this->config['sifre'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing PTT sifre (per-connection credentials).');
        }

        return $value;
    }

    private function kullanici(): string
    {
        return (string) ($this->config['kullanici'] ?? 'PttWs');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function redact(string $value): string
    {
        foreach (['sifre', 'musteri_id', 'kullanici'] as $key) {
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
