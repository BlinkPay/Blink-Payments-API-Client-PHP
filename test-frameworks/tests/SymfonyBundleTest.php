<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\FrameworkTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\FrameworkTest\Support\TokenCacheKeys;
use BlinkPay\BlinkDebit\Symfony\BlinkDebitBundle;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

class SymfonyBundleTest extends TestCase
{
    public const TEMP_DIR = '/blink-debit-bundle-test';

    /** The README's recommended way to drive the sandbox flag from the environment. */
    private const SANDBOX_FROM_ENV = '%env(bool:default:blink_debit.sandbox:BLINKPAY_SANDBOX)%';

    private ?KernelInterface $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        putenv('BLINKPAY_SANDBOX');
        unset($_ENV['BLINKPAY_SANDBOX'], $_SERVER['BLINKPAY_SANDBOX']);
        (new Filesystem())->remove(sys_get_temp_dir() . self::TEMP_DIR);
    }

    public function testBundleRegistersAnAutowirableClientWithTokensInTheConfiguredPool(): void
    {
        $container = $this->boot([
            'client_id' => 'symfony-id',
            'client_secret' => 'symfony-secret',
            'sandbox' => false,
            'timeout' => 9,
            'http_client' => 'test.psr18',
        ]);

        $client = $container->get('blink_debit.client');
        $this->assertInstanceOf(BlinkDebitClient::class, $client);
        $this->assertFalse($client->isSandbox());

        $this->assertSame('tok-1', $client->getAccessToken());
        $this->assertSame('tok-1', $client->getAccessToken());
        /** @var FakePsr18Client $psr18 */
        $psr18 = $container->get('test.psr18');
        $this->assertCount(1, $psr18->requests, 'Second token came from cache.app.');
        $this->assertSame('https://debit.blinkpay.co.nz/oauth2/token', (string) $psr18->requests[0]->getUri());

        /** @var CacheItemPoolInterface $pool */
        $pool = $container->get('test.cache_app');
        $this->assertTrue(
            $pool->getItem('blinkpay_' . TokenCacheKeys::token('symfony-id', false))->isHit(),
            'The token lives in the configured PSR-6 pool.'
        );
    }

    public function testOmittingSandboxMeansSandbox(): void
    {
        $container = $this->boot(['client_id' => 'id', 'client_secret' => 'secret']);

        $this->assertTrue($container->get('blink_debit.client')->isSandbox());
    }

    #[DataProvider('sandboxEnvironmentValues')]
    public function testDocumentedEnvPlaceholderTreatsUnsetAndBlankAsSandbox(?string $value, bool $expectedSandbox): void
    {
        if ($value !== null) {
            $_SERVER['BLINKPAY_SANDBOX'] = $value;
        }
        $container = $this->boot([
            'client_id' => 'id',
            'client_secret' => 'secret',
            'sandbox' => self::SANDBOX_FROM_ENV,
        ]);

        $this->assertSame($expectedSandbox, $container->get('blink_debit.client')->isSandbox());
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function sandboxEnvironmentValues(): iterable
    {
        yield 'unset' => [null, true];
        yield 'blank' => ['', true];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
    }

    /**
     * @param array<string, mixed> $blinkDebitConfig
     */
    private function boot(array $blinkDebitConfig): ContainerInterface
    {
        $this->kernel = new class('test', true, $blinkDebitConfig) extends Kernel {
            use MicroKernelTrait;

            /** @param array<string, mixed> $blinkDebitConfig */
            public function __construct(string $environment, bool $debug, private array $blinkDebitConfig)
            {
                parent::__construct($environment, $debug);
            }

            public function registerBundles(): iterable
            {
                return [new FrameworkBundle(), new BlinkDebitBundle()];
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(function (ContainerBuilder $container): void {
                    $container->setParameter('blink_debit.sandbox', true);
                    $container->loadFromExtension('framework', [
                        'secret' => 'test',
                        'test' => true,
                        'http_method_override' => false,
                        'handle_all_throwables' => true,
                        'php_errors' => ['log' => true],
                        'cache' => ['app' => 'cache.adapter.array'],
                    ]);
                    $container->loadFromExtension('blink_debit', $this->blinkDebitConfig);
                    $container->register('test.psr18', FakePsr18Client::class)->setPublic(true);
                    $container->setAlias('test.cache_app', 'cache.app')->setPublic(true);
                });
            }

            public function getCacheDir(): string
            {
                return sys_get_temp_dir() . SymfonyBundleTest::TEMP_DIR . '/cache/' . spl_object_id($this);
            }

            public function getLogDir(): string
            {
                return sys_get_temp_dir() . SymfonyBundleTest::TEMP_DIR . '/log';
            }
        };
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }
}

/**
 * PSR-18 client plus PSR-17 factories in one object, as Symfony's Psr18Client
 * is; returns a token response to everything.
 */
final class FakePsr18Client implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, [], '{"access_token":"tok-' . count($this->requests) . '","expires_in":3600}');
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->factory->createStreamFromResource($resource);
    }
}
