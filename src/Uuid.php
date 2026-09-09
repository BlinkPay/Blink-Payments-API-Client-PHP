<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Generates RFC 4122 version 4 UUIDs for idempotency keys, request IDs and
 * correlation IDs, so integrations need no extra dependency. Frameworks'
 * own helpers (Str::uuid(), Symfony Uuid::v4(), Text::uuid()) produce
 * interchangeable values.
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
