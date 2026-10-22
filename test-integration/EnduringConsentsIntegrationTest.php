<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\FlowType;
use BlinkPay\BlinkDebit\Enum\Period;
use BlinkPay\BlinkDebit\Request\Flow;
use BlinkPay\BlinkDebit\Uuid;

class EnduringConsentsIntegrationTest extends IntegrationTestCase
{
    public function testRedirectFlowEnduringConsentIsCreatedRetrievedAndRevoked(): void
    {
        $created = self::client()->createEnduringConsent(
            self::enduringConsentRequest(self::redirectFlow()),
            Uuid::v4()
        );
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        $this->assertStringContainsString('authorize', self::stringField($created, 'redirect_uri'));

        $consent = self::client()->getEnduringConsent($consentId);
        $this->assertSame($consentId, $consent['consent_id'] ?? null);
        $this->assertSame(ConsentStatus::AWAITING_AUTHORISATION, $consent['status'] ?? null);
        $this->assertSame('enduring', $consent['detail']['type'] ?? null);
        $this->assertSame(FlowType::REDIRECT, $consent['detail']['flow']['detail']['type'] ?? null);
        $this->assertSame(Period::FORTNIGHTLY, $consent['detail']['period'] ?? null);
        $this->assertSame('50.00', $consent['detail']['maximum_amount_period']['total'] ?? null);
        $this->assertSame('50.00', $consent['detail']['maximum_amount_payment']['total'] ?? null);

        self::client()->revokeEnduringConsent($consentId);

        $revoked = self::client()->getEnduringConsent($consentId);
        $this->assertSame(ConsentStatus::REVOKED, $revoked['status'] ?? null);
    }

    public function testGatewayFlowEnduringConsentRedirectsToTheHostedGateway(): void
    {
        $created = self::client()->createEnduringConsent(
            self::enduringConsentRequest(Flow::gateway(self::REDIRECT_URI)),
            Uuid::v4()
        );
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        $this->assertStringEndsWith('/gateway/pay?id=' . $consentId, self::stringField($created, 'redirect_uri'));

        $consent = self::client()->getEnduringConsent($consentId);
        $this->assertSame(ConsentStatus::GATEWAY_AWAITING_SUBMISSION, $consent['status'] ?? null);

        self::client()->revokeEnduringConsent($consentId);
    }

    public function testDecoupledFlowEnduringConsentIsAuthorisedBySandboxAndCanBeRevoked(): void
    {
        $consentId = self::authorisedEnduringConsentId();

        try {
            $consent = self::client()->getEnduringConsent($consentId);
            $this->assertSame(ConsentStatus::AUTHORISED, $consent['status'] ?? null);
            $this->assertSame(FlowType::DECOUPLED, $consent['detail']['flow']['detail']['type'] ?? null);
        } finally {
            self::client()->revokeEnduringConsent($consentId);
        }

        $revoked = self::client()->getEnduringConsent($consentId);
        $this->assertSame(ConsentStatus::REVOKED, $revoked['status'] ?? null);
    }

    public function testUnknownEnduringConsentIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getEnduringConsent(Uuid::v4());
        });
    }
}
