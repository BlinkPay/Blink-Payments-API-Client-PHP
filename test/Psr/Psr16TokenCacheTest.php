<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use BlinkPay\BlinkDebit\Psr\Psr16TokenCache;
use PHPUnit\Framework\TestCase;

class Psr16TokenCacheTest extends TestCase
{
    protected function setUp(): void
    {
        // The in-memory doubles implement the PHP 8 (v3) PSR interfaces, which
        // a PHP 7.4 install resolves to v1 instead.
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('PSR cache doubles require PHP 8.');
        }
    }

    public function testValuesArePrefixedAndTtlForwarded(): void
    {
        $store = new ArraySimpleCache();
        $cache = new Psr16TokenCache($store);

        $cache->set('blinkpay_token_abc', 'tok', 3300);
        $cache->set('blinkpay_scopes_abc', 'create:payment', null);

        $this->assertSame('tok', $store->values['blinkpay.blinkpay_token_abc']);
        $this->assertSame(3300, $store->ttls['blinkpay.blinkpay_token_abc']);
        $this->assertNull($store->ttls['blinkpay.blinkpay_scopes_abc']);
        $this->assertSame('tok', $cache->get('blinkpay_token_abc'));
    }

    public function testMissingAndNonStringValuesReadAsNull(): void
    {
        $store = new ArraySimpleCache();
        $store->values['blinkpay.odd'] = 42;
        $cache = new Psr16TokenCache($store);

        $this->assertNull($cache->get('missing'));
        $this->assertNull($cache->get('odd'));
    }

    public function testDeleteRemovesOnlyThePrefixedKey(): void
    {
        $store = new ArraySimpleCache();
        $store->values['unrelated'] = 'keep';
        $cache = new Psr16TokenCache($store, 'p.');
        $cache->set('k', 'v', 10);

        $cache->delete('k');

        $this->assertSame(['unrelated' => 'keep'], $store->values);
    }
}
