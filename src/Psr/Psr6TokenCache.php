<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Psr;

use BlinkPay\BlinkDebit\TokenCacheInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Token cache backed by any PSR-6 cache pool, the native contract of
 * Symfony Cache (`cache.app`) and of many standalone cache libraries.
 *
 * PSR-6 forbids some characters in keys (`{}()/\@:`); the client's keys are
 * `[a-z0-9_]` only, so the prefix must stay within the same set.
 *
 * Requires psr/cache, deliberately not a runtime dependency of this library.
 */
class Psr6TokenCache implements TokenCacheInterface
{
    private CacheItemPoolInterface $pool;

    private string $prefix;

    public function __construct(CacheItemPoolInterface $pool, string $prefix = 'blinkpay_')
    {
        $this->pool = $pool;
        $this->prefix = $prefix;
    }

    public function get(string $key): ?string
    {
        $item = $this->pool->getItem($this->prefix . $key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): void
    {
        $item = $this->pool->getItem($this->prefix . $key);
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->pool->save($item);
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($this->prefix . $key);
    }
}
