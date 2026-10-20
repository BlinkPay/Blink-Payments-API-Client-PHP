<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;
use BlinkPay\BlinkDebit\Exception\ConsentRejectedException;
use BlinkPay\BlinkDebit\Exception\ConsentTimeoutException;
use BlinkPay\BlinkDebit\Exception\ResourceNotFoundException;
use BlinkPay\BlinkDebit\Uuid;

/**
 * The await helpers against real sandbox timings: the outcome exceptions,
 * and the revoke-on-timeout matrix (a quick payment and an enduring consent
 * are revoked, a single consent is left open).
 */
class AwaitHelpersIntegrationTest extends IntegrationTestCase
{
    public function testDecoupledQuickPaymentSettles(): void
    {
        $created = self::client()->createQuickPayment(self::quickPaymentRequest(self::decoupledFlow()), Uuid::v4());
        $quickPaymentId = self::stringField($created, 'quick_payment_id');

        $quickPayment = self::client()->awaitSuccessfulQuickPayment($quickPaymentId, self::AWAIT_SECONDS);

        $this->assertSame($quickPaymentId, $quickPayment['quick_payment_id'] ?? null);
        $consent = $quickPayment['consent'] ?? null;
        $this->assertIsArray($consent);
        $this->assertContains($consent['status'] ?? null, [ConsentStatus::AUTHORISED, ConsentStatus::CONSUMED]);
        $this->assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $consent['payments'][0]['status'] ?? null);
    }

    public function testUnauthorisedQuickPaymentTimesOutAndIsRevoked(): void
    {
        $created = self::client()->createQuickPayment(self::quickPaymentRequest(self::redirectFlow()), Uuid::v4());
        $quickPaymentId = self::stringField($created, 'quick_payment_id');

        try {
            self::client()->awaitSuccessfulQuickPayment($quickPaymentId, self::TIMEOUT_SECONDS);
            $this->fail('A redirect quick payment nobody authorises must time out.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('has been revoked', $exception->getMessage());
        }

        $quickPayment = self::client()->getQuickPayment($quickPaymentId);
        $this->assertSame(ConsentStatus::REVOKED, $quickPayment['consent']['status'] ?? null);
    }

    public function testRevokedQuickPaymentIsReportedAsRejected(): void
    {
        $created = self::client()->createGatewayQuickPayment('1.25', self::REDIRECT_URI, self::pcr(), Uuid::v4());
        $quickPaymentId = self::stringField($created, 'quick_payment_id');
        self::client()->revokeQuickPayment($quickPaymentId);

        $this->expectException(ConsentRejectedException::class);
        $this->expectExceptionMessage('was revoked');

        self::client()->awaitSuccessfulQuickPayment($quickPaymentId, self::AWAIT_SECONDS);
    }

    public function testUnauthorisedSingleConsentTimesOutAndIsLeftOpen(): void
    {
        $created = self::client()->createSingleConsent(self::singleConsentRequest(self::redirectFlow()), Uuid::v4());
        $consentId = self::stringField($created, 'consent_id');

        try {
            self::client()->awaitAuthorisedSingleConsent($consentId, self::TIMEOUT_SECONDS);
            $this->fail('A redirect single consent nobody authorises must time out.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('was not authorised within', $exception->getMessage());
        }

        // The customer may still complete a single consent at the bank, so
        // the helper leaves it open; the test revokes it itself.
        $consent = self::client()->getSingleConsent($consentId);
        $this->assertSame(ConsentStatus::AWAITING_AUTHORISATION, $consent['status'] ?? null);
        self::client()->revokeSingleConsent($consentId);
    }

    public function testUnauthorisedEnduringConsentTimesOutAndIsRevoked(): void
    {
        $created = self::client()->createEnduringConsent(
            self::enduringConsentRequest(self::redirectFlow()),
            Uuid::v4()
        );
        $consentId = self::stringField($created, 'consent_id');

        try {
            self::client()->awaitAuthorisedEnduringConsent($consentId, self::TIMEOUT_SECONDS);
            $this->fail('A redirect enduring consent nobody authorises must time out.');
        } catch (ConsentTimeoutException $exception) {
            $this->assertStringContainsString('has been revoked', $exception->getMessage());
        }

        $consent = self::client()->getEnduringConsent($consentId);
        $this->assertSame(ConsentStatus::REVOKED, $consent['status'] ?? null);
    }

    public function testUnknownQuickPaymentIsReportedAtOnceRatherThanPolled(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        self::client()->awaitSuccessfulQuickPayment(Uuid::v4(), self::AWAIT_SECONDS);
    }
}
