<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\FrameworkTest\Support;

/**
 * Mirrors the client's private cache key scheme so a test can look inside the
 * framework's own cache store and prove the token landed there.
 */
final class TokenCacheKeys
{
    public static function token(string $clientId, bool $sandbox): string
    {
        return 'blinkpay_token_' . hash('sha256', ($sandbox ? 'sandbox' : 'production') . '|' . $clientId);
    }
}
