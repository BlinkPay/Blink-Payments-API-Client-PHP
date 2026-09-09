<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Env;
use BlinkPay\BlinkDebit\Exception\ConflictException;
use BlinkPay\BlinkDebit\Exception\ForbiddenException;
use BlinkPay\BlinkDebit\Exception\RateLimitExceededException;
use BlinkPay\BlinkDebit\Exception\ResourceNotFoundException;
use BlinkPay\BlinkDebit\Exception\ServerErrorException;
use BlinkPay\BlinkDebit\Exception\TransportException;
use BlinkPay\BlinkDebit\InMemoryTokenCache;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\RequestOptions;
use PHPUnit\Framework\TestCase;

/**
 * Retry, exception mapping, tracing headers and environment construction.
 */
class BlinkDebitClientResilienceTest extends TestCase
{
    private const UUID = '035d4ea4-4037-4110-9861-183eae1408b4';
    private const IDEM = 'ddc0315c-fba7-4926-ba24-b7700ed389e7';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private FakeTransport $transport;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        foreach ([Env::CLIENT_ID, Env::CLIENT_SECRET, Env::SANDBOX, Env::TIMEOUT] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function client(): BlinkDebitClient
    {
        $client = new BlinkDebitClient('client-id', 'client-secret', true, new InMemoryTokenCache(), $this->transport);
        $client->setSleep(function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });

        return $client;
    }

    private function queueToken(): void
    {
        $this->transport->queue(200, ['access_token' => 'tok', 'expires_in' => 3600, 'scope' => 'view:metadata']);
    }

    /**
     * @return list<string>
     */
    private function headersMatching(array $request, string $name): array
    {
        return array_values(array_filter($request['headers'], static function (string $header) use ($name): bool {
            return stripos($header, $name . ':') === 0;
        }));
    }

    // --- retry -----------------------------------------------------------

    public function testRateLimitedGetIsRetriedOnTheScheduleAndThenSucceeds(): void
    {
        $this->queueToken();
        $this->transport->queue(429, ['message' => 'slow down']);
        $this->transport->queue(429, ['message' => 'slow down']);
        $this->transport->queue(200, [['name' => 'BNZ']]);

        $banks = $this->client()->getMeta();

        $this->assertSame([['name' => 'BNZ']], $banks);
        $this->assertCount(4, $this->transport->requests);
        $this->assertSame([1000, 5000], $this->sleeps);
    }

    public function testRetryAfterHeaderIsHonouredWhenShort(): void
    {
        $this->queueToken();
        $this->transport->queue(429, [], ['retry-after' => '2']);
        $this->transport->queue(429, [], ['retry-after' => '600']);   // too long to wait: schedule wins
        $this->transport->queue(200, []);

        $this->client()->getMeta();

        $this->assertSame([2000, 5000], $this->sleeps);
    }

    public function testExhaustedRetriesRaiseTheTypedException(): void
    {
        $this->queueToken();
        for ($i = 0; $i < 3; $i++) {
            $this->transport->queue(429, ['message' => 'slow down']);
        }

        try {
            $this->client()->getMeta();
            $this->fail('Expected a RateLimitExceededException.');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertCount(4, $this->transport->requests);
        }
    }

    public function testServerErrorAndTransportFailureAreRetriedForAnIdempotentPost(): void
    {
        $this->queueToken();
        $this->transport->queue(503, ['message' => 'upstream']);
        $this->transport->queueFailure();
        $this->transport->queue(201, ['payment_id' => self::UUID]);

        $payment = $this->client()->createSingleConsentPayment(self::UUID, self::IDEM);

        $this->assertSame(self::UUID, $payment['payment_id']);
        $this->assertSame([1000, 5000], $this->sleeps);
        // Every attempt carried the same idempotency key and tracing IDs.
        $attempts = array_slice($this->transport->requests, 1);
        $this->assertCount(3, $attempts);
        $first = $attempts[0]['headers'];
        foreach ($attempts as $attempt) {
            $this->assertSame($this->headersMatching(['headers' => $first], 'idempotency-key'), $this->headersMatching($attempt, 'idempotency-key'));
            $this->assertSame($this->headersMatching(['headers' => $first], 'request-id'), $this->headersMatching($attempt, 'request-id'));
            $this->assertSame($this->headersMatching(['headers' => $first], 'x-correlation-id'), $this->headersMatching($attempt, 'x-correlation-id'));
        }
    }

    public function testPostWithoutIdempotencyKeyIsNotRetriedOnServerError(): void
    {
        $this->queueToken();
        $this->transport->queue(502, ['message' => 'bad gateway']);

        try {
            $this->client()->createAccountNumberRefund(self::UUID);
            $this->fail('Expected a ServerErrorException.');
        } catch (ServerErrorException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertCount(2, $this->transport->requests, 'The refund may have been created; do not resend blindly.');
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testPostWithoutIdempotencyKeyIsNotRetriedOnTransportFailure(): void
    {
        $this->queueToken();
        $this->transport->queueFailure('timed out');

        try {
            $this->client()->createSubscription('https://shop.example/hook', [BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_FAILED]);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame(0, $exception->getStatusCode());
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertCount(2, $this->transport->requests);
        }
    }

    public function testPostWithoutIdempotencyKeyIsStillRetriedOnRateLimit(): void
    {
        $this->queueToken();
        $this->transport->queue(429, []);
        $this->transport->queue(201, ['refund_id' => self::UUID]);

        $refund = $this->client()->createAccountNumberRefund(self::UUID);

        $this->assertSame(self::UUID, $refund['refund_id']);
        $this->assertSame([1000], $this->sleeps);
    }

    public function testRefundHelpersSendAnIdempotencyKeyWhenGiven(): void
    {
        $client = $this->client();
        $this->queueToken();
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(201, ['refund_id' => self::UUID]);
        }

        $client->createFullRefund(self::UUID, Pcr::build('Shop'), self::IDEM);
        $client->createPartialRefund(self::UUID, Pcr::build('Shop'), '1.00', self::IDEM);
        $client->createAccountNumberRefund(self::UUID, self::IDEM);
        $client->createRefund(['type' => 'account_number', 'payment_id' => self::UUID], self::IDEM);

        foreach (array_slice($this->transport->requests, 1) as $request) {
            $this->assertContains('idempotency-key: ' . self::IDEM, $request['headers']);
        }
    }

    public function testTransportFailureOnTheTokenEndpointIsRetried(): void
    {
        $this->transport->queueFailure();
        $this->queueToken();

        $this->assertSame('tok', $this->client()->getAccessToken());
        $this->assertSame([1000], $this->sleeps);
    }

    // --- exception mapping -------------------------------------------------

    public function testStatusCodesMapToExceptionSubclasses(): void
    {
        $cases = [
            403 => ForbiddenException::class,
            404 => ResourceNotFoundException::class,
            409 => ConflictException::class,
            422 => BlinkDebitApiException::class,
        ];

        foreach ($cases as $status => $class) {
            $this->transport = new FakeTransport();
            $this->queueToken();
            $this->transport->queue($status, ['message' => 'nope', 'code' => 'BP' . $status]);

            try {
                $this->client()->getPayment(self::UUID);
                $this->fail('Expected ' . $class);
            } catch (BlinkDebitApiException $exception) {
                $this->assertSame($class, get_class($exception));
                $this->assertSame($status, $exception->getStatusCode());
                $this->assertSame('BP' . $status, $exception->getErrorCode());
            }
        }
    }

    // --- tracing and identification ------------------------------------------

    public function testTracingIdsAreGeneratedWhenNotSupplied(): void
    {
        $this->queueToken();
        $this->transport->queue(200, []);

        $this->client()->getMeta();

        $request = $this->transport->lastRequest();
        $requestId = $this->headersMatching($request, 'request-id');
        $correlationId = $this->headersMatching($request, 'x-correlation-id');
        $this->assertCount(1, $requestId);
        $this->assertCount(1, $correlationId);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, substr($requestId[0], strlen('request-id: ')));
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, substr($correlationId[0], strlen('x-correlation-id: ')));
    }

    public function testCallerSuppliedTracingIdsAreNotOverridden(): void
    {
        $this->queueToken();
        $this->transport->queue(200, []);

        $this->client()->getMeta(RequestOptions::create()->withCorrelationId(self::IDEM));

        $request = $this->transport->lastRequest();
        $this->assertSame(['x-correlation-id: ' . self::IDEM], $this->headersMatching($request, 'x-correlation-id'));
        $this->assertCount(1, $this->headersMatching($request, 'request-id'));
    }

    public function testUserAgentIdentifiesTheSdkOnEveryRequest(): void
    {
        $this->queueToken();
        $this->transport->queue(200, []);

        $this->client()->getMeta();

        $expected = 'User-Agent: blink-debit-api-client-php/' . BlinkDebitClient::VERSION . ' php/' . PHP_VERSION;
        foreach ($this->transport->requests as $request) {
            $this->assertContains($expected, $request['headers'], $request['url']);
        }
    }

    public function testSubscriptionEventTypesAreValidatedLocally(): void
    {
        $client = $this->client();

        foreach ([[], ['urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-complete']] as $eventTypes) {
            try {
                $client->createSubscription('https://shop.example/hook', $eventTypes);
                $this->fail('Expected the event types to be rejected.');
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringContainsString('event type', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->transport->requests);
    }

    // --- environment ---------------------------------------------------------

    public function testFromEnvironmentDefaultsToSandboxWhenTheFlagIsUnsetOrBlank(): void
    {
        putenv(Env::CLIENT_ID . '=id');
        putenv(Env::CLIENT_SECRET . '=secret');

        $this->assertTrue(BlinkDebitClient::fromEnvironment(null, $this->transport)->isSandbox());

        putenv(Env::SANDBOX . '=');
        $this->assertTrue(BlinkDebitClient::fromEnvironment(null, $this->transport)->isSandbox());

        putenv(Env::SANDBOX . '=false');
        $this->assertFalse(BlinkDebitClient::fromEnvironment(null, $this->transport)->isSandbox());
    }

    public function testFromEnvironmentReadsCredentialsAndTimeout(): void
    {
        $_ENV[Env::CLIENT_ID] = 'id';
        $_ENV[Env::CLIENT_SECRET] = 'secret';
        $_ENV[Env::TIMEOUT] = '7';
        $this->queueToken();
        $this->transport->queue(200, []);

        $client = BlinkDebitClient::fromEnvironment(new InMemoryTokenCache(), $this->transport);
        $client->getMeta();

        $this->assertTrue($client->isConfigured());
        $this->assertSame(7, $this->transport->lastRequest()['timeout']);
        $this->assertSame('grant_type=client_credentials&client_id=id&client_secret=secret', $this->transport->requests[0]['body']);
    }

    public function testFromEnvironmentRejectsAnUnparseableSandboxFlag(): void
    {
        putenv(Env::SANDBOX . '=production');

        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('"production"');
        BlinkDebitClient::fromEnvironment(null, $this->transport);
    }

    public function testFromEnvironmentRejectsAnUnparseableTimeout(): void
    {
        putenv(Env::TIMEOUT . '=soon');

        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('BLINKPAY_TIMEOUT');
        BlinkDebitClient::fromEnvironment(null, $this->transport);
    }
}
