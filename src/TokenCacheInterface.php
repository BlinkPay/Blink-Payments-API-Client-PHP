<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * String cache used for access tokens and granted scopes.
 *
 * The client defaults to {@see ApcuTokenCache} when APCu is enabled and to
 * the per-process {@see InMemoryTokenCache} otherwise. Web integrations
 * without APCu should back this with a persistent cache so tokens survive
 * between requests: {@see \BlinkPay\BlinkDebit\Psr\Psr16TokenCache} for
 * Laravel, CakePHP and any PSR-16 store,
 * {@see \BlinkPay\BlinkDebit\Psr\Psr6TokenCache} for Symfony and any PSR-6
 * pool, or a bespoke implementation over a platform cache (Magento,
 * PrestaShop, WordPress transients). Values must be treated as secrets: never
 * store them anywhere that is logged, exported or written to disk.
 */
interface TokenCacheInterface
{
    /**
     * Returns the cached value, or null when missing or expired.
     */
    public function get(string $key): ?string;

    /**
     * Stores a value. A null TTL means "no expiry", or the store's default
     * lifetime where the store imposes one (PSR-6 and PSR-16 both leave that
     * to the implementation). It is used for the granted scopes, which should
     * outlive the token they arrived with; if the store expires them anyway,
     * hasScopes() reports the grant as unknown until the next token fetch,
     * which is benign.
     */
    public function set(string $key, string $value, ?int $ttlSeconds): void;

    public function delete(string $key): void;
}
