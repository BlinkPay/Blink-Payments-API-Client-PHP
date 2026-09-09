<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use Psr\Cache\CacheItemInterface;

class ArrayCacheItem implements CacheItemInterface
{
    public bool $hit = false;

    public mixed $value = null;

    /** @var int|\DateInterval|null Last expiresAfter() argument, recorded for assertions. */
    public mixed $ttl = null;

    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        $this->ttl = $time;

        return $this;
    }
}
