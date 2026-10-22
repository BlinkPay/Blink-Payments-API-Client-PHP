<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;
use BlinkPay\BlinkDebit\Uuid;

/**
 * Payments against consents the sandbox has authorised on its own.
 */
class PaymentsIntegrationTest extends IntegrationTestCase
{
    public function testSingleConsentPaymentIsCreatedAndSettles(): void
    {
        $consentId = self::authorisedSingleConsentId('50.00');

        $created = self::client()->createSingleConsentPayment($consentId, Uuid::v4());
        $paymentId = self::stringField($created, 'payment_id');
        self::assertUuid($paymentId, 'payment_id');

        $payment = self::client()->awaitSuccessfulPayment($paymentId, self::AWAIT_SECONDS);
        $this->assertSame($paymentId, $payment['payment_id'] ?? null);
        $this->assertSame('single', $payment['type'] ?? null);
        $this->assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $payment['status'] ?? null);
        $this->assertSame([], $payment['refunds'] ?? null);
        $this->assertSame($consentId, $payment['detail']['consent_id'] ?? null);
        $this->assertArrayHasKey('creation_timestamp', $payment);
        $this->assertArrayHasKey('status_updated_timestamp', $payment);

        // A single consent is spent by its one payment.
        $consent = self::client()->getSingleConsent($consentId);
        $this->assertSame(ConsentStatus::CONSUMED, $consent['status'] ?? null);
        $this->assertSame($paymentId, $consent['payments'][0]['payment_id'] ?? null);
    }

    public function testEnduringConsentPaymentIsCreatedAndSettles(): void
    {
        $consentId = self::authorisedEnduringConsentId();

        try {
            $created = self::client()->createEnduringConsentPayment($consentId, '45.00', self::pcr(), Uuid::v4());
            $paymentId = self::stringField($created, 'payment_id');
            self::assertUuid($paymentId, 'payment_id');

            $payment = self::client()->awaitSuccessfulPayment($paymentId, self::AWAIT_SECONDS);
            $this->assertSame($paymentId, $payment['payment_id'] ?? null);
            $this->assertSame('enduring', $payment['type'] ?? null);
            $this->assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $payment['status'] ?? null);
            $this->assertSame($consentId, $payment['detail']['consent_id'] ?? null);
            $this->assertSame('45.00', $payment['detail']['amount']['total'] ?? null);
            self::assertPcr($payment['detail']['pcr'] ?? null);
        } finally {
            self::client()->revokeEnduringConsent($consentId);
        }
    }

    public function testUnknownPaymentIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getPayment(Uuid::v4());
        });
    }
}
