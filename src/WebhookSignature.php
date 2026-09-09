<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Verifies the X-Signature header on webhook deliveries.
 *
 * The header has the form `t={unix_timestamp},v1={hmac_sha256_hex}`, where the
 * signature is HMAC-SHA256 over `{timestamp}.{raw_body}` keyed with the
 * subscription's secret. Verify against the raw request body exactly as
 * received — re-encoding decoded JSON changes the bytes and fails the check.
 *
 * Use the `event_id` in the payload to de-duplicate retried deliveries.
 */
final class WebhookSignature
{
    public const HEADER_NAME = 'X-Signature';

    /** Reject signatures whose timestamp is further than this from now, limiting replay windows. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * Whether the signature header authenticates the raw body. Never throws:
     * a malformed header, a stale timestamp or a mismatch all return false so
     * the webhook endpoint can respond 4xx uniformly.
     *
     * @param string   $rawBody          The request body bytes exactly as received.
     * @param string   $signatureHeader  The X-Signature header value.
     * @param string   $secret           The subscription secret returned on creation.
     * @param int      $toleranceSeconds Maximum accepted clock skew; 0 disables the timestamp check.
     * @param int|null $now              Unix time to compare against, defaulting to the current time.
     */
    public static function verify(
        string $rawBody,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): bool {
        if ($secret === '') {
            return false;
        }

        $parts = self::parse($signatureHeader);
        if ($parts === null) {
            return false;
        }

        if ($toleranceSeconds > 0 && abs(($now ?? time()) - $parts['timestamp']) > $toleranceSeconds) {
            return false;
        }

        $expected = self::sign($rawBody, $secret, $parts['timestamp']);

        return hash_equals($expected, $parts['signature']);
    }

    /**
     * Computes the v1 signature for a body and timestamp. Exposed so tests and
     * local tooling can produce valid headers.
     */
    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * @return array{timestamp: int, signature: string}|null
     */
    private static function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $element) {
            $pair = explode('=', trim($element), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && ctype_xdigit($value) && strlen($value) === 64) {
                $signature = strtolower($value);
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signature' => $signature];
    }
}
