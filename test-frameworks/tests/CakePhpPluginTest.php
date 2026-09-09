<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\FrameworkTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\CakePHP\BlinkDebitPlugin;
use BlinkPay\BlinkDebit\FrameworkTest\Support\RecordingTransport;
use BlinkPay\BlinkDebit\FrameworkTest\Support\TokenCacheKeys;
use BlinkPay\BlinkDebit\HttpTransportInterface;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Container;
use PHPUnit\Framework\TestCase;

class CakePhpPluginTest extends TestCase
{
    protected function setUp(): void
    {
        Cache::drop('blink_test');
        Cache::setConfig('blink_test', ['className' => 'Array']);
        Configure::delete(BlinkDebitPlugin::CONFIG_KEY);
    }

    protected function tearDown(): void
    {
        Cache::drop('blink_test');
        Configure::delete(BlinkDebitPlugin::CONFIG_KEY);
    }

    public function testPluginRegistersASharedClientWithTokensInTheCakeCache(): void
    {
        Configure::write(BlinkDebitPlugin::CONFIG_KEY, [
            'clientId' => 'cake-id',
            'clientSecret' => 'cake-secret',
            'sandbox' => 'false',
            'cacheConfig' => 'blink_test',
            'timeout' => 15,
        ]);
        $transport = new RecordingTransport();
        $container = new Container();
        $container->add(HttpTransportInterface::class, $transport);

        (new BlinkDebitPlugin())->services($container);

        $client = $container->get(BlinkDebitClient::class);
        $this->assertInstanceOf(BlinkDebitClient::class, $client);
        $this->assertSame($client, $container->get(BlinkDebitClient::class), 'Shared instance.');
        $this->assertFalse($client->isSandbox());

        $this->assertSame('tok-1', $client->getAccessToken());
        $this->assertSame('tok-1', $client->getAccessToken());
        $this->assertCount(1, $transport->requests);
        $this->assertSame(15, $transport->requests[0]['timeout']);
        $this->assertSame(
            'tok-1',
            Cache::read('blinkpay.' . TokenCacheKeys::token('cake-id', false), 'blink_test'),
            'The token lives in the Cake cache config, not in process memory.'
        );
    }

    public function testUnsetSandboxMeansSandbox(): void
    {
        Configure::write(BlinkDebitPlugin::CONFIG_KEY, [
            'clientId' => 'cake-id',
            'clientSecret' => 'cake-secret',
            'cacheConfig' => 'blink_test',
        ]);
        $container = new Container();
        (new BlinkDebitPlugin())->services($container);

        $this->assertTrue($container->get(BlinkDebitClient::class)->isSandbox());
    }
}
