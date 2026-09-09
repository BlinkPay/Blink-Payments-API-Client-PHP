<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\CakePHP;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Env;
use BlinkPay\BlinkDebit\HttpTransportInterface;
use BlinkPay\BlinkDebit\Psr\Psr16TokenCache;
use BlinkPay\BlinkDebit\TokenCacheInterface;
use Cake\Cache\Cache;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;

/**
 * CakePHP plugin (4.2+ and 5.x): registers BlinkDebitClient in the DI
 * container, configured from the `BlinkPay` key in config/app.php (or
 * app_local.php), with access tokens persisted in a Cake cache config.
 *
 *   // config/app_local.php
 *   'BlinkPay' => [
 *       'clientId' => env('BLINKPAY_CLIENT_ID', ''),
 *       'clientSecret' => env('BLINKPAY_CLIENT_SECRET', ''),
 *       'sandbox' => env('BLINKPAY_SANDBOX'),   // parsed by the plugin; unset or blank means sandbox
 *       'cacheConfig' => 'default',   // optional
 *       'timeout' => 30,              // optional, seconds
 *   ],
 *
 *   // src/Application.php
 *   $this->addPlugin(\BlinkPay\BlinkDebit\CakePHP\BlinkDebitPlugin::class);
 *
 * Controllers and commands then receive the client through constructor or
 * action injection by type-hinting BlinkDebitClient.
 *
 * To substitute the HTTP transport or token cache, register
 * HttpTransportInterface or TokenCacheInterface in your application's
 * services() before this plugin's; the plugin uses those entries when
 * present, otherwise cURL and the configured cache config.
 */
class BlinkDebitPlugin extends BasePlugin
{
    public const CONFIG_KEY = 'BlinkPay';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        // This plugin only provides services: it ships no bootstrap, routes,
        // middleware or console commands, so those hooks are switched off.
        parent::__construct($options + [
            'name' => 'BlinkDebit',
            'bootstrap' => false,
            'routes' => false,
            'middleware' => false,
            'console' => false,
        ]);
    }

    public function services(ContainerInterface $container): void
    {
        $container->addShared(BlinkDebitClient::class, static function () use ($container): BlinkDebitClient {
            /** @var array<string, mixed> $config */
            $config = (array) Configure::read(self::CONFIG_KEY, []);

            $tokenCache = $container->has(TokenCacheInterface::class)
                ? $container->get(TokenCacheInterface::class)
                : new Psr16TokenCache(Cache::pool((string) ($config['cacheConfig'] ?? 'default')));

            $transport = $container->has(HttpTransportInterface::class)
                ? $container->get(HttpTransportInterface::class)
                : null;

            $client = new BlinkDebitClient(
                (string) ($config['clientId'] ?? ''),
                (string) ($config['clientSecret'] ?? ''),
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
}
