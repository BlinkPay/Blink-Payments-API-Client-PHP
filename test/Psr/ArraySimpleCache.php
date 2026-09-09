<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal PSR-16 store recording TTLs, standing in for a framework cache.
 *
 * Implements psr/simple-cache v1 (untyped parameters, no return types) so the
 * same double parses and loads on PHP 7.4, the library's declared floor.
 */
class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int|\DateInterval|null> */
    public array $ttls = [];

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @param string                 $key
     * @param mixed                  $value
     * @param int|\DateInterval|null $ttl
     */
    public function set($key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    /**
     * @param string $key
     */
    public function delete($key): bool
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @param mixed            $default
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     * @param int|\DateInterval|null  $ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * @param string $key
     */
    public function has($key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
