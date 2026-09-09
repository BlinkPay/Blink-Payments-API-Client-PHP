<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * String cache used for access tokens and granted scopes.
 *
 * The default implementation is per-process only; web integrations should
 * back this with a persistent cache so tokens survive between requests:
 * {@see \BlinkPay\BlinkDebit\Psr\Psr16TokenCache} for Laravel, CakePHP and
 * any PSR-16 store, {@see \BlinkPay\BlinkDebit\Psr\Psr6TokenCache} for
 * Symfony and any PSR-6 pool, or a bespoke implementation over a platform
 * cache (Magento, PrestaShop, WordPress transients). Values must be treated
 * as secrets: never store them anywhere that is logged or exported.
 */
interface TokenCacheInterface
{
    /**
     * Returns the cached value, or null when missing or expired.
     */
    public function get(string $key): ?string;

    /**
     * Stores a value. A null TTL means "no expiry" (used for granted scopes,
     * which must outlive the token they arrived with).
     */
    public function set(string $key, string $value, ?int $ttlSeconds): void;

    public function delete(string $key): void;
}
