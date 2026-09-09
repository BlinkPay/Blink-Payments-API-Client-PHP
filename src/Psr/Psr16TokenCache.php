<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Psr;

use BlinkPay\BlinkDebit\TokenCacheInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Token cache backed by any PSR-16 simple cache. Laravel's cache repository,
 * CakePHP's cache engines and Symfony's Psr16Cache adapter all implement
 * PSR-16, so this one adapter covers all three frameworks.
 *
 * Keys are prefixed so several clients (or other libraries) can share a store,
 * and the client's own keys already carry an environment/client hash.
 *
 * Requires psr/simple-cache, deliberately not a runtime dependency of this
 * library: it arrives with the framework's cache component.
 */
class Psr16TokenCache implements TokenCacheInterface
{
    private CacheInterface $cache;

    private string $prefix;

    public function __construct(CacheInterface $cache, string $prefix = 'blinkpay.')
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($this->prefix . $key);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): void
    {
        $this->cache->set($this->prefix . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->prefix . $key);
    }
}
