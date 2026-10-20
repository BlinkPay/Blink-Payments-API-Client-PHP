<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\RefundStatus;
use BlinkPay\BlinkDebit\Enum\RefundType;
use BlinkPay\BlinkDebit\Uuid;

/**
 * Account-number refunds, the type the API processes for bank-settled
 * payments: the sandbox answers with a synthetic 99-...-00 account number.
 */
class RefundsIntegrationTest extends IntegrationTestCase
{
    public function testAccountNumberRefundForASingleConsentPaymentIsCreatedAndRetrieved(): void
    {
        $paymentId = self::settledSingleConsentPaymentId();

        $created = self::client()->createAccountNumberRefund($paymentId, Uuid::v4());
        $refundId = self::stringField($created, 'refund_id');
        self::assertUuid($refundId, 'refund_id');

        $this->assertCompletedAccountNumberRefund(self::processedRefund($refundId), $refundId, $paymentId);
    }

    public function testAccountNumberRefundForAnEnduringConsentPaymentIsCreatedAndRetrieved(): void
    {
        $consentId = self::authorisedEnduringConsentId();

        try {
            $created = self::client()->createEnduringConsentPayment($consentId, '45.00', self::pcr(), Uuid::v4());
            $paymentId = self::stringField($created, 'payment_id');
            self::client()->awaitSuccessfulPayment($paymentId, self::AWAIT_SECONDS);

            $created = self::client()->createAccountNumberRefund($paymentId, Uuid::v4());
            $refundId = self::stringField($created, 'refund_id');
            self::assertUuid($refundId, 'refund_id');

            $this->assertCompletedAccountNumberRefund(self::processedRefund($refundId), $refundId, $paymentId);
        } finally {
            self::client()->revokeEnduringConsent($consentId);
        }
    }

    public function testRefundRequestRepeatedWithTheSameIdempotencyKeyIsReplayed(): void
    {
        $paymentId = self::settledSingleConsentPaymentId();
        $idempotencyKey = Uuid::v4();

        $first = self::client()->createAccountNumberRefund($paymentId, $idempotencyKey);
        $second = self::client()->createAccountNumberRefund($paymentId, $idempotencyKey);

        $this->assertSame(
            self::stringField($first, 'refund_id'),
            self::stringField($second, 'refund_id'),
            'The API replays the original refund for a retried key and payload rather than refunding twice.'
        );
    }

    public function testUnknownRefundIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getRefund(Uuid::v4());
        });
    }

    /**
     * Reads the refund once a second until it leaves `processing`, for up to
     * AWAIT_SECONDS: a created refund is not necessarily processed yet, as
     * the getRefund() docblock says. The sandbox answers at once; the loop
     * keeps the test honest about the contract.
     *
     * @return array<string, mixed>
     */
    private static function processedRefund(string $refundId): array
    {
        for ($attempt = 1; ; $attempt++) {
            $refund = self::client()->getRefund($refundId);
            if (($refund['status'] ?? null) !== RefundStatus::PROCESSING || $attempt >= self::AWAIT_SECONDS) {
                return $refund;
            }
            sleep(1);
        }
    }

    /**
     * @param array<string, mixed> $refund
     */
    private function assertCompletedAccountNumberRefund(array $refund, string $refundId, string $paymentId): void
    {
        $this->assertSame($refundId, $refund['refund_id'] ?? null);
        $this->assertSame(RefundStatus::COMPLETED, $refund['status'] ?? null);
        $accountNumber = self::stringField($refund, 'account_number');
        $this->assertStringStartsWith('99-', $accountNumber);
        $this->assertStringEndsWith('-00', $accountNumber);
        $this->assertSame(RefundType::ACCOUNT_NUMBER, $refund['detail']['type'] ?? null);
        $this->assertSame($paymentId, $refund['detail']['payment_id'] ?? null);
        $this->assertArrayHasKey('creation_timestamp', $refund);
        $this->assertArrayHasKey('status_updated_timestamp', $refund);
    }
}
