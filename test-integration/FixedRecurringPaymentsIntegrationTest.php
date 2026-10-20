<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\RetryStrategy;
use BlinkPay\BlinkDebit\Request\FixedRecurringPaymentRequest;
use BlinkPay\BlinkDebit\Uuid;

class FixedRecurringPaymentsIntegrationTest extends IntegrationTestCase
{
    public function testScheduleIsCreatedRetrievedAndCancelled(): void
    {
        $consentId = self::authorisedEnduringConsentId();

        try {
            $this->assertScheduleLifecycle($consentId);
        } finally {
            self::client()->revokeEnduringConsent($consentId);
        }
    }

    private function assertScheduleLifecycle(string $consentId): void
    {
        $created = self::client()->createFixedRecurringPayment(
            FixedRecurringPaymentRequest::build($consentId, '10.00', self::pcr(), null, RetryStrategy::NONE),
            Uuid::v4()
        );
        $fixedRecurringPaymentId = self::stringField($created, 'fixed_recurring_payment_id');

        try {
            self::assertUuid($fixedRecurringPaymentId, 'fixed_recurring_payment_id');

            $schedule = self::client()->getFixedRecurringPayment($fixedRecurringPaymentId);
            $this->assertSame($fixedRecurringPaymentId, $schedule['fixed_recurring_payment_id'] ?? null);
            $this->assertSame('active', $schedule['status'] ?? null);
            $this->assertSame($consentId, $schedule['consent_id'] ?? null);
            $this->assertSame('10.00', $schedule['amount']['total'] ?? null);
            self::assertPcr($schedule['pcr'] ?? null);
            $this->assertSame(RetryStrategy::NONE, $schedule['retry_strategy'] ?? null);
            $this->assertArrayHasKey('start_date', $schedule);
            $this->assertArrayHasKey('next_payment_date', $schedule);
        } finally {
            // Always cancelled, so a failed assertion cannot leave a schedule
            // debiting the sandbox consent every fortnight.
            self::client()->cancelFixedRecurringPayment($fixedRecurringPaymentId);
        }

        $cancelled = self::client()->getFixedRecurringPayment($fixedRecurringPaymentId);
        $this->assertSame('cancelled', $cancelled['status'] ?? null);
    }

    public function testUnknownScheduleIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getFixedRecurringPayment(Uuid::v4());
        });
    }
}
