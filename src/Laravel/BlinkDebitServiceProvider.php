<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Laravel;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Env;
use BlinkPay\BlinkDebit\HttpTransportInterface;
use BlinkPay\BlinkDebit\Psr\Psr16TokenCache;
use BlinkPay\BlinkDebit\TokenCacheInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider: binds a shared BlinkDebitClient configured from
 * config/blinkpay.php, with access tokens persisted in the application cache.
 *
 * Registered automatically through package discovery. Type-hint
 * BlinkDebitClient in a controller, job or action to receive it. The
 * provider is not deferred: the singleton closure is already lazy, and a
 * deferred provider's boot() would never run, which would hide the
 * `blinkpay-config` publish tag from `vendor:publish`.
 *
 * To substitute the HTTP transport or token cache, bind
 * HttpTransportInterface or TokenCacheInterface in your own provider; this
 * provider uses those bindings when present, otherwise cURL and the
 * configured cache store.
 */
class BlinkDebitServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__ . '/../../config/blinkpay.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'blinkpay');

        $this->app->singleton(BlinkDebitClient::class, static function (Container $app): BlinkDebitClient {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('blinkpay', []);

            $tokenCache = $app->bound(TokenCacheInterface::class)
                ? $app->make(TokenCacheInterface::class)
                : new Psr16TokenCache($app->make(CacheFactory::class)->store($config['cache_store'] ?? null));

            $transport = $app->bound(HttpTransportInterface::class)
                ? $app->make(HttpTransportInterface::class)
                : null;

            $client = new BlinkDebitClient(
                (string) ($config['client_id'] ?? ''),
                (string) ($config['client_secret'] ?? ''),
                Env::bool($config['sandbox'] ?? null, true),
                $tokenCache,
                $transport
            );

            if (!empty($config['timeout'])) {
                $client->setRequestTimeout((int) $config['timeout']);
            }

            return $client;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('blinkpay.php')], 'blinkpay-config');
        }
    }
}
