<?php

declare(strict_types=1);

namespace Adaa\Shipping\Support;

/**
 * HMAC-SHA256 webhook signing shared by the package controller and any
 * host implementation. Secrets never appear in exceptions or logs —
 * verification returns a plain boolean.
 */
final class WebhookSigner
{
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Accepts plain hex (any case), `sha256=`-prefixed hex, and base64.
     */
    public static function verify(string $candidate, string $payload, string $secret): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '' || $secret === '') {
            return false;
        }

        if (str_starts_with(strtolower($candidate), 'sha256=')) {
            $candidate = substr($candidate, 7);
        }

        $hex = self::sign($payload, $secret);

        if (hash_equals($hex, strtolower($candidate))) {
            return true;
        }

        $raw = hex2bin($hex);

        return $raw !== false && $raw !== '' && hash_equals(base64_encode($raw), $candidate);
    }

    /**
     * First non-empty signature header value, checking common names.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function fromHeaders(array $headers): ?string
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }

        foreach (['x-signature', 'x-hub-signature-256', 'x-webhook-signature', 'signature'] as $name) {
            $value = $normalized[$name] ?? null;
            $candidate = is_array($value) ? (string) reset($value) : (string) $value;

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
