<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Symfony;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Psr\Psr18Transport;
use BlinkPay\BlinkDebit\Psr\Psr6TokenCache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony bundle (Symfony 6.1+): registers BlinkDebitClient as an autowirable
 * service configured under the `blink_debit` key, with access tokens persisted
 * in a PSR-6 cache pool (`cache.app` by default). In a stock application
 * `cache.app` is the filesystem adapter, which writes bearer tokens to disk;
 * set `framework.cache.app` to a memory adapter (redis, memcached, apcu) or
 * point `cache` at a pool that uses one.
 *
 *   # config/packages/blink_debit.yaml
 *   blink_debit:
 *     client_id: '%env(BLINKPAY_CLIENT_ID)%'
 *     client_secret: '%env(BLINKPAY_CLIENT_SECRET)%'
 *     # sandbox: false                                           # omit for sandbox; see below
 *     # http_client: Symfony\Component\HttpClient\Psr18Client   # optional, defaults to cURL
 *
 * Leave `sandbox` out to stay in sandbox. Symfony's `%env(bool:...)%`
 * processor turns an unset or blank variable into false, i.e. production, so
 * an environment-driven value should go through the `default:` processor with
 * a parameter that is true: `'%env(bool:default:blink_debit.sandbox:BLINKPAY_SANDBOX)%'`
 * with `parameters: { blink_debit.sandbox: true }`. The order matters:
 * `default:` must see the raw variable, before `bool:` casts a blank to false.
 *
 * Older Symfony versions can wire the same three services by hand; see the
 * README.
 */
final class BlinkDebitBundle extends AbstractBundle
{
    protected string $extensionAlias = 'blink_debit';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('client_id')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('client_secret')->isRequired()->cannotBeEmpty()->end()
                ->booleanNode('sandbox')
                    ->defaultTrue()
                    ->info('Sandbox is the safe default; production must be opted into explicitly.')
                ->end()
                ->integerNode('timeout')
                    ->defaultNull()
                    ->min(1)
                    ->info('Request timeout in seconds, covering the token fetch.')
                ->end()
                ->scalarNode('cache')
                    ->defaultValue('cache.app')
                    ->info(
                        'Service id of the PSR-6 cache pool that persists access tokens. Prefer a memory-backed '
                        . 'pool: the stock cache.app is filesystem-backed and would write bearer tokens to disk.'
                    )
                ->end()
                ->scalarNode('http_client')
                    ->defaultNull()
                    ->info(
                        'Service id of a PSR-18 client that also implements the PSR-17 request and stream '
                        . 'factories (e.g. Symfony\Component\HttpClient\Psr18Client). Null uses cURL.'
                    )
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('blink_debit.token_cache', Psr6TokenCache::class)
            ->args([service($config['cache'])]);

        $transport = null;
        if ($config['http_client'] !== null) {
            $services->set('blink_debit.transport', Psr18Transport::class)
                ->args([
                    service($config['http_client']),
                    service($config['http_client']),
                    service($config['http_client']),
                ]);
            $transport = service('blink_debit.transport');
        }

        $client = $services->set(BlinkDebitClient::class)
            ->args([
                $config['client_id'],
                $config['client_secret'],
                $config['sandbox'],
                service('blink_debit.token_cache'),
                $transport,
            ]);

        if ($config['timeout'] !== null) {
            $client->call('setRequestTimeout', [$config['timeout']]);
        }

        $services->alias('blink_debit.client', BlinkDebitClient::class)->public();
    }
}
