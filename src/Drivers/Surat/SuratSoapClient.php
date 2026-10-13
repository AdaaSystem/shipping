<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Surat;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Raw-XML SOAP 1.1 wrapper around the Sürat Kargo `services.asmx` service —
 * no ext-soap. Envelopes are built as strings and POSTed over the
 * injectable HTTP factory with `Content-Type: text/xml` + `SOAPAction`,
 * then parsed with SimpleXML.
 *
 * Operation + field names follow the reference
 * `mohamedhekal/shipbridge-surat` source (`SoapSuratGateway` op names
 * `GonderiyiKargoyaGonderYeniSiparisBarkodOlustur` /
 * `KargoTakipHareketDetayliV2` / `GonderiGeriCek` with `CariKodu` /
 * `Sifre` / `WebSiparisKodu` / `IptalNeden`, plus the create payload field
 * map in its `Support/PayloadFactory`). The live WSDL itself answers 403
 * from here, so the `http://tempuri.org/` namespace is assumed from the
 * published `prova.suratkargo.com.tr` service page ("This web service is
 * using http://tempuri.org/ as its default namespace") — override via
 * config `namespace` / `soap_action` if the live WSDL disagrees.
 *
 * No facades, no global state, no caching. Credentials never appear in
 * exceptions or in returned `raw` payloads.
 */
final class SuratSoapClient
{
    public const PRODUCTION_ENDPOINT = 'https://webservices.suratkargo.com.tr/services.asmx';

    public const TEST_ENDPOINT = 'https://prova.suratkargo.com.tr/services.asmx';

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
     * Create via `GonderiyiKargoyaGonderYeniSiparisBarkodOlustur`.
     *
     * @param  array<string, mixed>  $params  Flat shipment fields.
     * @return array{tracking_number: string, barcode: string, message: string, raw: array<string, mixed>}
     */
    public function createShipmentWithBarcode(array $params): array
    {
        $xml = $this->call('GonderiyiKargoyaGonderYeniSiparisBarkodOlustur', $this->flatXml($params));

        $result = $this->firstNode($xml, 'GonderiyiKargoyaGonderYeniSiparisBarkodOlusturResult') ?? $this->firstNode($xml, 'Result');

        $barcode = $result !== null ? $this->child($result, ['TakipNo', 'Barkod', 'Barcode']) : '';
        $webSiparis = (string) ($params['OzelKargoTakipNo'] ?? '');

        if ($result !== null && $webSiparis === '') {
            $webSiparis = $this->child($result, ['OzelKargoTakipNo']);
        }

        $message = $result !== null ? $this->child($result, ['Sonuc', 'Message']) : '';

        if ($message !== '' && $this->looksLikeError($message)) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Sürat create failed: '.$message)
            );
        }

        $trackingNumber = $webSiparis !== '' ? $webSiparis : $barcode;

        if ($trackingNumber === '') {
            throw ShippingException::carrierFailed('Sürat create did not return TakipNo or OzelKargoTakipNo.');
        }

        return [
            'tracking_number' => $trackingNumber,
            'barcode' => $barcode,
            'message' => $message,
            'raw' => ['barcode' => $barcode, 'web_siparis_kodu' => $webSiparis, 'message' => $message],
        ];
    }

    /**
     * Track via `KargoTakipHareketDetayliV2`.
     *
     * @return array{events: list<array{durum: string, aciklama: string, tarih: ?string, sube: ?string}>, raw: array<string, mixed>}
     */
    public function trackDetailed(string $webSiparisKodu): array
    {
        $xml = $this->call('KargoTakipHareketDetayliV2', $this->flatXml([
            'CariKodu' => $this->cariKodu(),
            'Sifre' => $this->sifre(),
            'WebSiparisKodu' => $webSiparisKodu,
        ]));

        $result = $this->firstNode($xml, 'KargoTakipHareketDetayliV2Result');

        /** @var list<array{durum: string, aciklama: string, tarih: ?string, sube: ?string}> $events */
        $events = [];

        if ($result !== null) {
            $rows = [];

            foreach ($this->children($result, 'Hareket') as $row) {
                $statusRaw = $this->child($row, ['Durum', 'Aciklama']);

                if ($statusRaw === '') {
                    continue;
                }

                $tarih = $this->child($row, ['Tarih']);
                $sube = $this->child($row, ['Sube']);

                $rows[] = [
                    'durum' => $statusRaw,
                    'aciklama' => $this->child($row, ['Aciklama']) !== '' ? $this->child($row, ['Aciklama']) : $statusRaw,
                    'tarih' => $tarih !== '' ? $tarih : null,
                    'sube' => $sube !== '' ? $sube : null,
                ];
            }

            $events = array_reverse($rows);
        }

        return [
            'events' => $events,
            'raw' => ['events' => $events],
        ];
    }

    /**
     * Cancel via `GonderiGeriCek`. Returns the result text.
     */
    public function withdrawShipment(string $webSiparisKodu, string $reason): string
    {
        $xml = $this->call('GonderiGeriCek', $this->flatXml([
            'CariKodu' => $this->cariKodu(),
            'Sifre' => $this->sifre(),
            'WebSiparisKodu' => $webSiparisKodu,
            'IptalNeden' => $reason,
        ]));

        $result = $this->scalar($xml, 'GonderiGeriCekResult', 'GonderiGeriCek', false);

        if ($result !== '' && $this->looksLikeError($result)) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Sürat cancel failed: '.$result)
            );
        }

        return $result !== '' ? $result : 'OK';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function flatXml(array $params): string
    {
        $inner = '';

        foreach ($params as $key => $value) {
            $tag = (string) $key;

            if ($tag === '' || $value === null) {
                continue;
            }

            $inner .= '<'.$tag.'>'.$this->escape((string) $value).'</'.$tag.'>';
        }

        return $inner;
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
                'X-Requested-By' => 'adaa-shipping-surat',
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
                $this->safeMessage('Sürat '.$operation.' SOAP Fault: '.$faultMessage),
                $response->status(),
            );
        }

        $suffix = $body !== '' && strlen($body) < 300 ? ': '.$this->redact($body) : '.';

        throw ShippingException::carrierFailed(
            'Sürat '.$operation.' failed (HTTP '.$response->status().')'.$suffix,
            $response->status(),
        );
    }

    private function parse(string $body, string $operation): SimpleXMLElement
    {
        if ($body === '') {
            throw ShippingException::carrierFailed('Sürat '.$operation.' returned an empty response.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();

        if ($xml === false) {
            throw ShippingException::carrierFailed('Sürat '.$operation.' returned invalid XML.');
        }

        $faultMessage = $this->faultMessage($body, $xml);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('Sürat '.$operation.' SOAP Fault: '.$faultMessage)
            );
        }

        return $xml;
    }

    private function scalar(SimpleXMLElement $xml, string $node, string $operation, bool $required = true): string
    {
        $found = $xml->xpath('//*[local-name()="'.$node.'"]');

        if (! is_array($found) || ! isset($found[0])) {
            if ($required) {
                throw ShippingException::carrierFailed('Sürat '.$operation.' response was missing '.$node.'.');
            }

            return '';
        }

        return trim((string) $found[0]);
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
     * @return list<SimpleXMLElement>
     */
    private function children(SimpleXMLElement $node, string $name): array
    {
        /** @var list<SimpleXMLElement> $nodes */
        $nodes = $node->xpath('.//*[local-name()="'.$name.'"]') ?: [];

        return $nodes;
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

    private function cariKodu(): string
    {
        $value = $this->config['kullanici_adi'] ?? $this->config['cari'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing Sürat kullanici_adi (per-connection credentials).');
        }

        return $value;
    }

    private function sifre(): string
    {
        $value = $this->config['sifre'] ?? null;

        if (! is_string($value) || $value === '') {
            throw ShippingException::carrierFailed('Missing Sürat sifre (per-connection credentials).');
        }

        return $value;
    }

    private function looksLikeError(string $value): bool
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return ! in_array($lower, ['tamam', 'ok', 'success', 'başarılı', 'basarili'], true)
            && (str_contains($lower, 'hata') || str_contains($lower, 'error') || str_contains($lower, 'fail'));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function redact(string $value): string
    {
        foreach (['sifre', 'web_sifre', 'kullanici_adi', 'cari'] as $key) {
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
