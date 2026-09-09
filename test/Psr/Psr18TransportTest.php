<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Psr\Psr18Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Psr18TransportTest extends TestCase
{
    /** @var RequestInterface[] */
    private array $sent = [];

    private function transport(callable $handler): Psr18Transport
    {
        $factory = new Psr17Factory();
        $client = new class($handler, $this->sent) implements ClientInterface {
            /** @var callable */
            private $handler;
            private array $sent;

            public function __construct(callable $handler, array &$sent)
            {
                $this->handler = $handler;
                $this->sent = &$sent;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return ($this->handler)($request);
            }
        };

        return new Psr18Transport($client, $factory, $factory);
    }

    public function testRequestIsTranslatedToPsr7AndResponseBack(): void
    {
        $transport = $this->transport(static fn (): ResponseInterface => new Response(201, [], '{"payment_id":"p1"}'));

        $result = $transport->send(
            'POST',
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/payments',
            ['Authorization: Bearer tok', 'Content-Type: application/json', 'malformed-line'],
            '{"consent_id":"c1"}',
            30
        );

        $this->assertSame(['status' => 201, 'body' => '{"payment_id":"p1"}'], $result);
        $request = $this->sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/payments', (string) $request->getUri());
        $this->assertSame('Bearer tok', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"consent_id":"c1"}', (string) $request->getBody());
    }

    public function testRequestWithoutBodySendsAnEmptyStream(): void
    {
        $transport = $this->transport(static fn (): ResponseInterface => new Response(204));

        $result = $transport->send('DELETE', 'https://example.test/x', [], null, 30);

        $this->assertSame(['status' => 204, 'body' => ''], $result);
        $this->assertSame('', (string) $this->sent[0]->getBody());
    }

    public function testClientExceptionsBecomeTransportFailures(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            throw new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {
            };
        });

        try {
            $transport->send('GET', 'https://example.test/x', [], null, 30);
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(0, $exception->getStatusCode());
            $this->assertStringContainsString('connection refused', $exception->getMessage());
        }
    }

    public function testClientWorksEndToEndOverThePsr18Transport(): void
    {
        $responses = [
            new Response(200, [], '{"access_token":"tok","expires_in":3600,"scope":"view:metadata"}'),
            new Response(200, [], '[{"name":"BNZ"}]'),
        ];
        $transport = $this->transport(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $client = new BlinkDebitClient('id', 'secret', true, null, $transport);

        $banks = $client->getMeta();

        $this->assertSame([['name' => 'BNZ']], $banks);
        $this->assertSame('Bearer tok', $this->sent[1]->getHeaderLine('Authorization'));
    }
}
