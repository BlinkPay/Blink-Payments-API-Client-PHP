<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use Psr\Cache\CacheItemInterface;

/**
 * Implements psr/cache v1 (untyped parameters, no return types) so the same
 * double parses and loads on PHP 7.4, the library's declared floor.
 */
class ArrayCacheItem implements CacheItemInterface
{
    public bool $hit = false;

    /** @var mixed */
    public $value = null;

    /** @var int|\DateInterval|null Last expiresAfter() argument, recorded for assertions. */
    public $ttl = null;

    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return mixed
     */
    public function get()
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    /**
     * @param mixed $value
     */
    public function set($value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @param \DateTimeInterface|null $expiration
     */
    public function expiresAt($expiration): self
    {
        return $this;
    }

    /**
     * @param int|\DateInterval|null $time
     */
    public function expiresAfter($time): self
    {
        $this->ttl = $time;

        return $this;
    }
}
