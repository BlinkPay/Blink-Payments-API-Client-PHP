<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\FlowType;
use BlinkPay\BlinkDebit\Uuid;

class SingleConsentsIntegrationTest extends IntegrationTestCase
{
    public function testRedirectFlowSingleConsentIsCreatedRetrievedAndRevoked(): void
    {
        $created = self::client()->createSingleConsent(self::singleConsentRequest(self::redirectFlow()), Uuid::v4());
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        $this->assertStringContainsString('authorize', self::stringField($created, 'redirect_uri'));

        $consent = self::client()->getSingleConsent($consentId);
        $this->assertSame($consentId, $consent['consent_id'] ?? null);
        $this->assertSame(ConsentStatus::AWAITING_AUTHORISATION, $consent['status'] ?? null);
        $this->assertSame([], $consent['payments'] ?? null);
        $this->assertArrayHasKey('creation_timestamp', $consent);
        $this->assertSame('single', $consent['detail']['type'] ?? null);
        $this->assertSame(FlowType::REDIRECT, $consent['detail']['flow']['detail']['type'] ?? null);
        $this->assertSame('1.25', $consent['detail']['amount']['total'] ?? null);
        self::assertPcr($consent['detail']['pcr'] ?? null);

        self::client()->revokeSingleConsent($consentId);

        $revoked = self::client()->getSingleConsent($consentId);
        $this->assertSame(ConsentStatus::REVOKED, $revoked['status'] ?? null);
        $this->assertArrayHasKey('status_updated_timestamp', $revoked);
    }

    public function testGatewayFlowSingleConsentRedirectsToTheHostedGateway(): void
    {
        $created = self::client()->createGatewaySingleConsent(
            '1.25',
            self::REDIRECT_URI,
            self::pcr(),
            Uuid::v4(),
            self::CUSTOMER_HASH
        );
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        $this->assertStringEndsWith('/gateway/pay?id=' . $consentId, self::stringField($created, 'redirect_uri'));

        $consent = self::client()->getSingleConsent($consentId);
        $this->assertSame(ConsentStatus::GATEWAY_AWAITING_SUBMISSION, $consent['status'] ?? null);

        self::client()->revokeSingleConsent($consentId);
    }

    public function testDecoupledFlowSingleConsentIsAuthorisedBySandbox(): void
    {
        $consentId = self::authorisedSingleConsentId();

        $consent = self::client()->getSingleConsent($consentId);
        $this->assertSame(ConsentStatus::AUTHORISED, $consent['status'] ?? null);
        $this->assertSame(FlowType::DECOUPLED, $consent['detail']['flow']['detail']['type'] ?? null);
        $flowDetail = $consent['detail']['flow']['detail'] ?? [];
        $this->assertArrayHasKey('identifier_value', $flowDetail);
        $this->assertContains($flowDetail['identifier_value'], [self::PHONE_NUMBER, null], 'Blink Debit nulls it once the bank has the request.');
    }

    public function testUnknownSingleConsentIsNotFound(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->getSingleConsent(Uuid::v4());
        });
    }
}
