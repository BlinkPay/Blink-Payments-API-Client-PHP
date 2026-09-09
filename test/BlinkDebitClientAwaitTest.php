<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;
use BlinkPay\BlinkDebit\Exception\ConflictException;
use BlinkPay\BlinkDebit\Exception\ConsentRejectedException;
use BlinkPay\BlinkDebit\Exception\ConsentTimeoutException;
use BlinkPay\BlinkDebit\Exception\PaymentRejectedException;
use BlinkPay\BlinkDebit\Exception\PaymentTimeoutException;
use BlinkPay\BlinkDebit\Exception\ResourceNotFoundException;
use BlinkPay\BlinkDebit\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

/**
 * The await helpers: polling cadence, outcome exceptions and the auto-revoke
 * matrix (quick payment and enduring consent revoke on timeout; single
 * consent and payment do not).
 */
class BlinkDebitClientAwaitTest extends TestCase
{
    private const ID = '035d4ea4-4037-4110-9861-183eae1408b4';

    private FakeTransport $transport;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
        $this->transport->queue(200, ['access_token' => 'tok', 'expires_in' => 3600]);
    }

    private function client(): BlinkDebitClient
    {
        $client = new BlinkDebitClient('client-id', 'client-secret', true, new InMemoryTokenCache(), $this->transport);
        $client->setSleep(function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });

        return $client;
    }

    /**
     * @param array<int, array<string, mixed>> $payments
     */
    private function queueQuickPayment(string $consentStatus, array $payments = []): void
    {
        $this->transport->queue(200, [
            'quick_payment_id' => self::ID,
            'consent' => ['consent_id' => self::ID, 'status' => $consentStatus, 'payments' => $payments],
        ]);
    }

    private function queueConsent(string $status): void
    {
        $this->transport->queue(200, ['consent_id' => self::ID, 'status' => $status]);
    }

    private function queuePayment(string $status): void
    {
        $this->transport->queue(200, ['payment_id' => self::ID, 'status' => $status]);
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_column(array_slice($this->transport->requests, 1), 'method');
    }

    // --- quick payment -------------------------------------------------------

    public function testQuickPaymentReturnsOnceThePaymentSettles(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->queueQuickPayment(ConsentStatus::AUTHORISED);
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::PENDING]]);
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED]]);

        $quickPayment = $this->client()->awaitSuccessfulQuickPayment(self::ID, 300);

        $this->assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $quickPayment['consent']['payments'][0]['status']);
        $this->assertSame(['GET', 'GET', 'GET', 'GET'], $this->methods());
        $this->assertSame([1000, 1000, 1000], $this->sleeps, 'One second between polls, none after the last.');
    }

    public function testQuickPaymentRejectedConsentThrowsWithoutRevoking(): void
    {
        $this->queueQuickPayment(ConsentStatus::REJECTED);

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 300);
            $this->fail('Expected a ConsentRejectedException.');
        } catch (ConsentRejectedException $exception) {
            $this->assertStringContainsString('rejected', $exception->getMessage());
            $this->assertSame(['GET'], $this->methods());
        }
    }

    public function testQuickPaymentGatewayTimeoutThrowsConsentTimeout(): void
    {
        $this->queueQuickPayment(ConsentStatus::GATEWAY_TIMEOUT);

        $this->expectException(ConsentTimeoutException::class);
        $this->expectExceptionMessage('gateway timed out');
        $this->client()->awaitSuccessfulQuickPayment(self::ID, 300);
    }

    public function testQuickPaymentRejectedPaymentThrowsPaymentRejected(): void
    {
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::REJECTED]]);

        $this->expectException(PaymentRejectedException::class);
        $this->client()->awaitSuccessfulQuickPayment(self::ID, 300);
    }

    public function testQuickPaymentUnauthorisedAtTimeoutIsRevoked(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queueRaw(204, '');

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 2);
            $this->fail('Expected a ConsentTimeoutException.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('has been revoked', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame(['GET', 'GET', 'DELETE'], $this->methods());
            $this->assertSame([1000], $this->sleeps);
        }
    }

    public function testQuickPaymentFailedRevokeIsAttachedToTheTimeout(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queue(404, ['message' => 'gone']);

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 1);
            $this->fail('Expected a ConsentTimeoutException.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('left unrevoked', $exception->getMessage());
            $this->assertInstanceOf(ResourceNotFoundException::class, $exception->getPrevious());
        }
    }

    public function testQuickPaymentAuthorisedDuringTheRevokeIsReportedAsInFlight(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queue(409, ['message' => 'consent already authorised']);
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_IN_PROCESS]]);

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 1);
            $this->fail('Expected a PaymentTimeoutException.');
        } catch (PaymentTimeoutException $exception) {
            $this->assertStringContainsString('keep polling', $exception->getMessage());
            $this->assertSame(['GET', 'DELETE', 'GET'], $this->methods(), 'The 409 triggers one re-read.');
        }
    }

    public function testQuickPaymentSettledDuringTheRevokeIsReturned(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queue(409, ['message' => 'consent already authorised']);
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED]]);

        $result = $this->client()->awaitSuccessfulQuickPayment(self::ID, 1);

        $this->assertSame(ConsentStatus::CONSUMED, $result['consent']['status']);
        $this->assertSame(['GET', 'DELETE', 'GET'], $this->methods());
    }

    public function testQuickPaymentStillPendingAfterARevokeConflictIsATimeout(): void
    {
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queue(409, ['message' => 'some other conflict']);
        $this->queueQuickPayment(ConsentStatus::AWAITING_AUTHORISATION);

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 1);
            $this->fail('Expected a ConsentTimeoutException.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('left unrevoked', $exception->getMessage());
            $this->assertInstanceOf(ConflictException::class, $exception->getPrevious());
        }
    }

    public function testQuickPaymentAuthorisedButUnsettledAtTimeoutIsNotRevoked(): void
    {
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_IN_PROCESS]]);
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_IN_PROCESS]]);

        try {
            $this->client()->awaitSuccessfulQuickPayment(self::ID, 2);
            $this->fail('Expected a PaymentTimeoutException.');
        } catch (PaymentTimeoutException $exception) {
            $this->assertStringContainsString('keep polling', $exception->getMessage());
            $this->assertSame(['GET', 'GET'], $this->methods(), 'Funds may be in flight: nothing is revoked.');
        }
    }

    public function testQuickPaymentTransientFetchErrorsAreAbsorbed(): void
    {
        // The first retrieval initiates the debit; a 5xx there leaves the outcome unknown.
        for ($i = 0; $i < 3; $i++) {
            $this->transport->queue(500, ['message' => 'oops']);
        }
        $this->queueQuickPayment(ConsentStatus::CONSUMED, [['status' => PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED]]);

        $quickPayment = $this->client()->awaitSuccessfulQuickPayment(self::ID, 5);

        $this->assertSame(self::ID, $quickPayment['quick_payment_id']);
        $this->assertSame([1000, 5000, 1000], $this->sleeps, 'Two request retries, then one poll interval.');
    }

    // --- single consent --------------------------------------------------------

    public function testSingleConsentReturnsWhenAuthorised(): void
    {
        $this->queueConsent(ConsentStatus::AWAITING_AUTHORISATION);
        $this->queueConsent(ConsentStatus::AUTHORISED);

        $consent = $this->client()->awaitAuthorisedSingleConsent(self::ID, 60);

        $this->assertSame(ConsentStatus::AUTHORISED, $consent['status']);
        $this->assertSame([1000], $this->sleeps);
    }

    public function testSingleConsentAlreadyConsumedCountsAsAuthorised(): void
    {
        $this->queueConsent(ConsentStatus::CONSUMED);

        $this->assertSame(ConsentStatus::CONSUMED, $this->client()->awaitAuthorisedSingleConsent(self::ID, 60)['status']);
    }

    public function testSingleConsentRevokedThrowsConsentRejected(): void
    {
        $this->queueConsent(ConsentStatus::REVOKED);

        $this->expectException(ConsentRejectedException::class);
        $this->client()->awaitAuthorisedSingleConsent(self::ID, 60);
    }

    public function testSingleConsentTimeoutDoesNotRevoke(): void
    {
        $this->queueConsent(ConsentStatus::AWAITING_AUTHORISATION);

        try {
            $this->client()->awaitAuthorisedSingleConsent(self::ID, 1);
            $this->fail('Expected a ConsentTimeoutException.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertSame(['GET'], $this->methods());
        }
    }

    // --- enduring consent ------------------------------------------------------

    public function testEnduringConsentTimeoutRevokes(): void
    {
        $this->queueConsent(ConsentStatus::AWAITING_AUTHORISATION);
        $this->transport->queueRaw(204, '');

        try {
            $this->client()->awaitAuthorisedEnduringConsent(self::ID, 1);
            $this->fail('Expected a ConsentTimeoutException.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertSame(['GET', 'DELETE'], $this->methods());
            $this->assertStringContainsString('/enduring-consents/' . self::ID, $this->transport->lastRequest()['url']);
        }
    }

    public function testEnduringConsentReturnsWhenAuthorised(): void
    {
        $this->queueConsent(ConsentStatus::AUTHORISED);

        $this->assertSame(ConsentStatus::AUTHORISED, $this->client()->awaitAuthorisedEnduringConsent(self::ID, 60)['status']);
    }

    // --- payment -----------------------------------------------------------------

    public function testPaymentReturnsWhenSettled(): void
    {
        $this->queuePayment(PaymentStatus::PENDING);
        $this->queuePayment(PaymentStatus::ACCEPTED_SETTLEMENT_IN_PROCESS);
        $this->queuePayment(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED);

        $payment = $this->client()->awaitSuccessfulPayment(self::ID, 60);

        $this->assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $payment['status']);
        $this->assertSame([1000, 1000], $this->sleeps);
    }

    public function testPaymentRejectedThrows(): void
    {
        $this->queuePayment(PaymentStatus::REJECTED);

        $this->expectException(PaymentRejectedException::class);
        $this->client()->awaitSuccessfulPayment(self::ID, 60);
    }

    public function testPaymentTimeoutThrowsWithoutRevoking(): void
    {
        $this->queuePayment(PaymentStatus::PENDING);
        $this->queuePayment(PaymentStatus::PENDING);

        try {
            $this->client()->awaitSuccessfulPayment(self::ID, 2);
            $this->fail('Expected a PaymentTimeoutException.');
        } catch (PaymentTimeoutException $exception) {
            $this->assertSame(['GET', 'GET'], $this->methods());
        }
    }

    public function testNonTransientErrorsPropagateAtOnce(): void
    {
        $this->transport->queue(404, ['message' => 'no such payment']);

        $this->expectException(ResourceNotFoundException::class);
        $this->client()->awaitSuccessfulPayment(self::ID, 60);
    }
}
