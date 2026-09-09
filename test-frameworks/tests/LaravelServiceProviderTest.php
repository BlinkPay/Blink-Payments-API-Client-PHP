<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\FrameworkTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\FrameworkTest\Support\RecordingTransport;
use BlinkPay\BlinkDebit\FrameworkTest\Support\TokenCacheKeys;
use BlinkPay\BlinkDebit\HttpTransportInterface;
use BlinkPay\BlinkDebit\Laravel\BlinkDebitServiceProvider;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;

class LaravelServiceProviderTest extends TestCase
{
    private RecordingTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [BlinkDebitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->transport = new RecordingTransport();
        $app->instance(HttpTransportInterface::class, $this->transport);
        $app['config']->set('cache.default', 'array');
    }

    public function testClientIsResolvedFromPublishedConfigAndPersistsTokensInTheLaravelCache(): void
    {
        config()->set('blinkpay.client_id', 'laravel-id');
        config()->set('blinkpay.client_secret', 'laravel-secret');
        config()->set('blinkpay.sandbox', 'false');
        config()->set('blinkpay.timeout', 12);

        $client = $this->app->make(BlinkDebitClient::class);

        $this->assertSame($client, $this->app->make(BlinkDebitClient::class), 'Bound as a singleton.');
        $this->assertFalse($client->isSandbox());
        $this->assertSame('tok-1', $client->getAccessToken());
        $this->assertSame('tok-1', $client->getAccessToken(), 'Second call is served from the cache.');
        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(12, $this->transport->requests[0]['timeout']);
        $this->assertSame('https://debit.blinkpay.co.nz/oauth2/token', $this->transport->requests[0]['url']);
        $this->assertSame(
            'tok-1',
            Cache::store('array')->get('blinkpay.' . TokenCacheKeys::token('laravel-id', false)),
            'The token lives in the Laravel cache, not in process memory.'
        );
    }

    public function testBlankSandboxValueStillMeansSandbox(): void
    {
        // Laravel's env() returns '' for BLINKPAY_SANDBOX= in .env; a (bool) cast would make that production.
        config()->set('blinkpay.client_id', 'laravel-id');
        config()->set('blinkpay.client_secret', 'laravel-secret');
        config()->set('blinkpay.sandbox', '');

        $this->assertTrue($this->app->make(BlinkDebitClient::class)->isSandbox());
    }

    public function testDefaultConfigIsSandboxWithAThirtySecondTimeout(): void
    {
        $this->assertTrue(config('blinkpay.sandbox'));
        $this->assertSame(30, config('blinkpay.timeout'));
        $this->assertNull(config('blinkpay.cache_store'));
    }
}
