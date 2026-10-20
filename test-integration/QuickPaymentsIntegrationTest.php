<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\FlowType;
use BlinkPay\BlinkDebit\Uuid;

class QuickPaymentsIntegrationTest extends IntegrationTestCase
{
    public function testRedirectFlowQuickPaymentIsCreatedRetrievedAndRevoked(): void
    {
        $created = self::client()->createQuickPayment(self::quickPaymentRequest(self::redirectFlow()), Uuid::v4());
        $quickPaymentId = self::stringField($created, 'quick_payment_id');
        self::assertUuid($quickPaymentId, 'quick_payment_id');
        $this->assertStringContainsString('authorize', self::stringField($created, 'redirect_uri'));

        $quickPayment = self::client()->getQuickPayment($quickPaymentId);
        $this->assertSame($quickPaymentId, $quickPayment['quick_payment_id'] ?? null);
        $consent = $quickPayment['consent'] ?? null;
        $this->assertIsArray($consent);
        $this->assertSame(ConsentStatus::AWAITING_AUTHORISATION, $consent['status'] ?? null);
        $this->assertSame([], $consent['payments'] ?? null);
        $this->assertSame('single', $consent['detail']['type'] ?? null);
        $this->assertSame(FlowType::REDIRECT, $consent['detail']['flow']['detail']['type'] ?? null);
        $this->assertSame(self::BANK, $consent['detail']['flow']['detail']['bank'] ?? null);
        $this->assertSame('1.25', $consent['detail']['amount']['total'] ?? null);
        self::assertPcr($consent['detail']['pcr'] ?? null);

        self::client()->revokeQuickPayment($quickPaymentId);

        $revoked = self::client()->getQuickPayment($quickPaymentId);
        $this->assertSame(ConsentStatus::REVOKED, $revoked['consent']['status'] ?? null);
    }

    public function testGatewayFlowQuickPaymentRedirectsToTheHostedGateway(): void
    {
        $created = self::client()->createGatewayQuickPayment(
            '1.25',
            self::REDIRECT_URI,
            self::pcr(),
            Uuid::v4(),
            self::CUSTOMER_HASH
        );
        $quickPaymentId = self::stringField($created, 'quick_payment_id');
        self::assertUuid($quickPaymentId, 'quick_payment_id');
        $this->assertStringEndsWith('/gateway/pay?id=' . $quickPaymentId, self::stringField($created, 'redirect_uri'));

        $quickPayment = self::client()->getQuickPayment($quickPaymentId);
        $this->assertSame(ConsentStatus::GATEWAY_AWAITING_SUBMISSION, $quickPayment['consent']['status'] ?? null);
        $this->assertSame(FlowType::GATEWAY, $quickPayment['consent']['detail']['flow']['detail']['type'] ?? null);

        self::client()->revokeQuickPayment($quickPaymentId);
    }

    public function testDecoupledFlowQuickPaymentIsCreatedWithoutARedirect(): void
    {
        $created = self::client()->createQuickPayment(self::quickPaymentRequest(self::decoupledFlow()), Uuid::v4());
        $quickPaymentId = self::stringField($created, 'quick_payment_id');
        self::assertUuid($quickPaymentId, 'quick_payment_id');
        $this->assertArrayNotHasKey('redirect_uri', $created, 'The bank pushes the authorisation to the customer\'s app.');

        $quickPayment = self::client()->getQuickPayment($quickPaymentId);
        $this->assertSame(FlowType::DECOUPLED, $quickPayment['consent']['detail']['flow']['detail']['type'] ?? null);
        $this->assertSame(self::PHONE_NUMBER, $quickPayment['consent']['detail']['flow']['detail']['identifier_value'] ?? null);
    }

    public function testUnknownQuickPaymentIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getQuickPayment(Uuid::v4());
        });
    }
}
