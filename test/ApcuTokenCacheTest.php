<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\ApcuTokenCache;
use PHPUnit\Framework\TestCase;

/**
 * Runs only where APCu is loaded and enabled for the CLI (apc.enable_cli=1);
 * CI installs the extension so the default token cache is exercised there.
 */
class ApcuTokenCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ApcuTokenCache::isAvailable()) {
            $this->markTestSkipped('APCu is not available in this process.');
        }
        apcu_clear_cache();
    }

    public function testRoundTripAndDelete(): void
    {
        $cache = new ApcuTokenCache('test.');

        $this->assertNull($cache->get('token'));
        $cache->set('token', 'abc', 3600);
        $this->assertSame('abc', $cache->get('token'));
        $this->assertTrue(apcu_exists('test.token'), 'Keys are prefixed in the shared store.');

        $cache->delete('token');
        $this->assertNull($cache->get('token'));
    }

    public function testNullTtlMeansNoExpiry(): void
    {
        $cache = new ApcuTokenCache('test.');
        $cache->set('scopes', 'a b', null);

        $this->assertSame('a b', $cache->get('scopes'));
    }

    public function testNonStringValuesAreIgnored(): void
    {
        apcu_store('test.token', ['not' => 'a string']);

        $this->assertNull((new ApcuTokenCache('test.'))->get('token'));
    }
}
