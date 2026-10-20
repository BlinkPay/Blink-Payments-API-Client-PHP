<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Uuid;

class SubscriptionsIntegrationTest extends IntegrationTestCase
{
    public function testWebhookSubscriptionIsCreatedListedAndDeleted(): void
    {
        // A callback URL must be whitelisted for the client (400 BP214
        // otherwise); the sample return page is, the sample callback is not.
        $created = self::client()->createSubscription(
            self::REDIRECT_URI,
            [BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_COMPLETED]
        );
        $subscriptionId = self::stringField($created, 'subscription_id');

        try {
            self::assertUuid($subscriptionId, 'subscription_id');
            $this->assertNotSame('', self::stringField($created, 'secret'), 'The signing secret is returned on creation.');

            $listed = array_column(self::client()->getSubscriptions(), 'subscription_id');
            $this->assertContains($subscriptionId, $listed);
        } finally {
            // Always deleted, so a failed assertion cannot leave the sandbox
            // client posting events to the sample return page.
            self::client()->deleteSubscription($subscriptionId);
        }

        $this->assertNotContains($subscriptionId, array_column(self::client()->getSubscriptions(), 'subscription_id'));
    }

    public function testUnknownSubscriptionCannotBeDeleted(): void
    {
        self::assertNotFound(static function (): void {
            self::client()->deleteSubscription(Uuid::v4());
        });
    }
}
