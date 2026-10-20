<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Env;
use BlinkPay\BlinkDebit\Exception\UnauthorisedException;
use BlinkPay\BlinkDebit\InMemoryTokenCache;

/**
 * The client-credentials grant against the sandbox token endpoint.
 */
class OAuthIntegrationTest extends IntegrationTestCase
{
    public function testAccessTokenIsAJwtCarryingTheExpectedScopes(): void
    {
        $client = new BlinkDebitClient(
            (string) Env::get(Env::CLIENT_ID),
            (string) Env::get(Env::CLIENT_SECRET),
            true,
            new InMemoryTokenCache()
        );

        $token = $client->getAccessToken();

        $this->assertStringStartsWith('ey', $token, 'A JWT begins with a base64url-encoded JSON header.');

        $scopes = $client->getGrantedScopes();
        $this->assertNotNull($scopes, 'The token response carries the granted scopes.');
        foreach ([
            BlinkDebitClient::SCOPE_CREATE_PAYMENT,
            BlinkDebitClient::SCOPE_VIEW_PAYMENT,
            BlinkDebitClient::SCOPE_VIEW_METADATA,
            BlinkDebitClient::SCOPE_CREATE_SINGLE_CONSENT,
            BlinkDebitClient::SCOPE_VIEW_SINGLE_CONSENT,
            BlinkDebitClient::SCOPE_REVOKE_SINGLE_CONSENT,
            BlinkDebitClient::SCOPE_CREATE_ENDURING_CONSENT,
            BlinkDebitClient::SCOPE_VIEW_ENDURING_CONSENT,
            BlinkDebitClient::SCOPE_REVOKE_ENDURING_CONSENT,
            BlinkDebitClient::SCOPE_CREATE_QUICK_PAYMENT,
            BlinkDebitClient::SCOPE_VIEW_QUICK_PAYMENT,
            BlinkDebitClient::SCOPE_CREATE_REFUND,
            BlinkDebitClient::SCOPE_VIEW_REFUND,
        ] as $scope) {
            $this->assertContains($scope, $scopes);
        }
        $this->assertTrue($client->hasRefundScopes());
    }

    public function testWrongSecretIsReportedAsUnauthorised(): void
    {
        $client = new BlinkDebitClient(
            (string) Env::get(Env::CLIENT_ID),
            'not-the-secret',
            true,
            new InMemoryTokenCache()
        );

        $this->expectException(UnauthorisedException::class);
        $this->expectExceptionMessage('check the client ID and client secret');

        $client->getAccessToken();
    }
}
