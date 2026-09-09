<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Minimal PSR-6 pool recording expiry, standing in for Symfony Cache.
 *
 * Implements psr/cache v1 (untyped `$key` parameters, no return types) so the
 * same double parses and loads on PHP 7.4, the library's declared floor.
 */
class ArrayCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, ArrayCacheItem> */
    public array $items = [];

    /**
     * @param string $key
     */
    public function getItem($key): CacheItemInterface
    {
        return $this->items[$key] ?? new ArrayCacheItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }

        return $result;
    }

    /**
     * @param string $key
     */
    public function hasItem($key): bool
    {
        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @param string $key
     */
    public function deleteItem($key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof ArrayCacheItem) {
            return false;
        }
        $item->hit = true;
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
