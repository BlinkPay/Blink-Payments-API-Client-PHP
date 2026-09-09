<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Consent statuses. Authorised means a payment may be created; Consumed means
 * one has been. Rejected, Revoked and GatewayTimeout are terminal.
 */
final class ConsentStatus
{
    public const GATEWAY_AWAITING_SUBMISSION = 'GatewayAwaitingSubmission';
    public const GATEWAY_TIMEOUT = 'GatewayTimeout';
    public const AWAITING_AUTHORISATION = 'AwaitingAuthorisation';
    public const AUTHORISED = 'Authorised';
    public const CONSUMED = 'Consumed';
    public const REJECTED = 'Rejected';
    public const REVOKED = 'Revoked';

    private function __construct()
    {
    }
}
