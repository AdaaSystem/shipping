<?php

declare(strict_types=1);

namespace Adaa\Shipping\Drivers\Smsa;

use Adaa\Shipping\Exceptions\ShippingException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Raw-XML SOAP 1.1 wrapper around the SMSA SECOM web service.
 *
 * Portability decision: no ext-soap. Envelopes are built as strings and
 * POSTed over the injectable HTTP factory with `Content-Type: text/xml`
 * + `SOAPAction`, then parsed with SimpleXML (ext-simplexml/ext-libxml
 * are bundled with PHP by default).
 *
 * Auth is the per-operation `passKey`/`passkey` value from per-connection
 * config only (`passkey|pass_key|passKey|token`). The live WSDL
 * (`http://track.smsaexpress.com/SECOM/SMSAwebService.asmx?WSDL`) defines
 * no `ClientInfo`/username/password/accountNumber/accountPin fields for
 * SECOM — only `passKey`/`passkey` per operation — so this client follows
 * the WSDL, not a ClientInfo shape.
 *
 * No facades, no global state, no caching. Credentials never appear in
 * exceptions or in returned `raw` payloads.
 */
final class SmsaSoapClient
{
    public const PRODUCTION_ENDPOINT = 'http://track.smsaexpress.com/SECOM/SMSAwebService.asmx';

    public const NAMESPACE = 'http://track.smsaexpress.com/secom/';

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
     * Create a shipment via `addShip`. Returns the SMSA AWB number.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addShip(array $payload): string
    {
        $xml = $this->call('addShip', $payload);
        $awb = $this->scalar($xml, 'addShipResult', 'addShip');

        if ($this->looksLikeError($awb)) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('SMSA addShip failed'.($awb !== '' ? ': '.$awb : '.'))
            );
        }

        return $awb;
    }

    /**
     * Track via `getTrack`.
     *
     * @return array{requestStatus: string, events: list<array{awbNo: string, occurredAt: ?string, activity: string, details: string, location: ?string}>, raw: array<string, mixed>}
     */
    public function getTrack(string $awb): array
    {
        $xml = $this->call('getTrack', [
            'awbNo' => $awb,
            'passkey' => $this->passKey(),
        ]);

        $statusNodes = $xml->xpath('//*[local-name()="RequestStatus"]');
        $requestStatus = '';

        if (is_array($statusNodes) && isset($statusNodes[0])) {
            $requestStatus = trim((string) $statusNodes[0]);
        }

        $events = $this->trackEvents($xml);

        return [
            'requestStatus' => $requestStatus,
            'events' => $events,
            'raw' => [
                'request_status' => $requestStatus,
                'events' => $events,
            ],
        ];
    }

    /**
     * Current status via `getStatus`.
     *
     * @return array{status: string, raw: array<string, mixed>}
     */
    public function getStatus(string $awb): array
    {
        $xml = $this->call('getStatus', [
            'awbNo' => $awb,
            'passkey' => $this->passKey(),
        ]);

        $status = $this->scalar($xml, 'getStatusResult', 'getStatus');

        return [
            'status' => $status,
            'raw' => ['status' => $status],
        ];
    }

    /**
     * AWB label PDF via `getPDF`. Returns the `getPDFResult` contents as
     * transmitted (base64 text for base64Binary).
     */
    public function getPdf(string $awb): string
    {
        $xml = $this->call('getPDF', [
            'awbNo' => $awb,
            'passKey' => $this->passKey(),
        ]);

        $contents = $this->scalar($xml, 'getPDFResult', 'getPDF');

        if ($contents !== '' && $this->looksLikeError($contents) && strlen($contents) < 500) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('SMSA getPDF failed: '.$contents)
            );
        }

        return $contents;
    }

    /**
     * Cancel via `cancelShipment` (WSDL: `awbNo` + `passkey` + `reas`).
     */
    public function cancelShipment(string $awb, string $reason = 'Cancelled by sender'): string
    {
        $xml = $this->call('cancelShipment', [
            'awbNo' => $awb,
            'passkey' => $this->passKey(),
            'reas' => $reason,
        ]);

        $result = $this->scalar($xml, 'cancelShipmentResult', 'cancelShipment');

        if ($this->looksLikeError($result)) {
            throw ShippingException::carrierFailed(
                $this->safeMessage('SMSA cancelShipment failed'.($result !== '' ? ': '.$result : '.'))
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(string $operation, array $params): SimpleXMLElement
    {
        $body = $this->post($operation, $params);

        return $this->parse($body, $operation);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $operation, array $params): string
    {
        $envelope = $this->envelope($operation, $params);
        $timeout = (int) ($this->config['timeout'] ?? 45);

        /** @var Response $response */
        $response = $this->http
            ->timeout($timeout > 0 ? $timeout : 45)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => self::NAMESPACE.$operation,
                'X-Requested-By' => 'adaa-shipping-smsa',
            ])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($this->endpoint());

        $body = $response->body();

        if (! is_string($body)) {
            $body = '';
        }

        if ($response->successful()) {
            return $body;
        }

        $faultMessage = $this->faultMessage($body);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('SMSA '.$operation.' SOAP Fault: '.$faultMessage),
                $response->status(),
            );
        }

        $suffix = $body !== '' && strlen($body) < 300 ? ': '.$this->redact($body) : '.';

        throw ShippingException::carrierFailed(
            'SMSA '.$operation.' failed (HTTP '.$response->status().')'.$suffix,
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function envelope(string $operation, array $params): string
    {
        $inner = '';

        foreach ($params as $key => $value) {
            $tag = (string) $key;

            if ($tag === '') {
                continue;
            }

            $inner .= '<'.$tag.'>'.$this->escape((string) $value).'</'.$tag.'>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<'.$operation.' xmlns="'.self::NAMESPACE.'">'
            .$inner
            .'</'.$operation.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';
    }

    private function parse(string $body, string $operation): SimpleXMLElement
    {
        if ($body === '') {
            throw ShippingException::carrierFailed('SMSA '.$operation.' returned an empty response.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();

        if ($xml === false) {
            throw ShippingException::carrierFailed('SMSA '.$operation.' returned invalid XML.');
        }

        $faultMessage = $this->faultMessage($body, $xml);

        if ($faultMessage !== '') {
            throw ShippingException::carrierFailed(
                $this->safeMessage('SMSA '.$operation.' SOAP Fault: '.$faultMessage)
            );
        }

        return $xml;
    }

    private function scalar(SimpleXMLElement $xml, string $node, string $operation): string
    {
        $found = $xml->xpath('//*[local-name()="'.$node.'"]');

        if (! is_array($found) || ! isset($found[0])) {
            throw ShippingException::carrierFailed('SMSA '.$operation.' response was missing '.$node.'.');
        }

        return trim((string) $found[0]);
    }

    /**
     * @return list<array{awbNo: string, occurredAt: ?string, activity: string, details: string, location: ?string}>
     */
    private function trackEvents(SimpleXMLElement $xml): array
    {
        $nodes = $xml->xpath('//*[local-name()="TrackDetails"]');

        /** @var list<array{awbNo: string, occurredAt: ?string, activity: string, details: string, location: ?string}> $events */
        $events = [];

        if (! is_array($nodes)) {
            return $events;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof SimpleXMLElement) {
                continue;
            }

            $activity = $this->child($node, ['Activity', 'activity', 'Status', 'status']);
            $details = $this->child($node, ['Details', 'details']);
            $location = $this->child($node, ['Location', 'location']);
            $date = $this->child($node, ['evtDate', 'Date', 'date']);
            $time = $this->child($node, ['Time', 'time']);
            $awbNo = $this->child($node, ['awbNo', 'AwbNo']);

            if ($activity === '' && $details === '') {
                continue;
            }

            $occurred = trim($date.($time !== '' ? ' '.$time : ''));

            $events[] = [
                'awbNo' => $awbNo,
                'occurredAt' => $occurred !== '' ? $occurred : null,
                'activity' => $activity !== '' ? $activity : $details,
                'details' => $details !== '' ? $details : $activity,
                'location' => $location !== '' ? $location : null,
            ];
        }

        return $events;
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

        $details = $xml->xpath('//*[local-name()="detail"]');

        if (is_array($details) && isset($details[0])) {
            $detail = trim((string) $details[0]);

            if ($detail !== '') {
                return $detail;
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
            $endpoint = explode('?', $override, 2)[0];

            return rtrim($endpoint, '/');
        }

        return self::PRODUCTION_ENDPOINT;
    }

    private function passKey(): string
    {
        $key = $this->config['passkey']
            ?? $this->config['pass_key']
            ?? $this->config['passKey']
            ?? $this->config['token']
            ?? null;

        if (! is_string($key) || $key === '') {
            throw ShippingException::carrierFailed('Missing SMSA passkey (per-connection credentials).');
        }

        return $key;
    }

    private function looksLikeError(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $lower = strtolower($value);

        return str_contains($lower, 'error')
            || str_contains($lower, 'failed')
            || str_contains($lower, 'invalid')
            || str_contains($lower, 'not found')
            || str_contains($lower, 'fault');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function redact(string $value): string
    {
        $key = null;

        try {
            $key = $this->passKey();
        } catch (ShippingException) {
            $key = null;
        }

        if (is_string($key) && $key !== '' && str_contains($value, $key)) {
            return str_replace($key, '***', $value);
        }

        return $value;
    }

    private function safeMessage(string $message): string
    {
        return $this->redact($message);
    }
}
