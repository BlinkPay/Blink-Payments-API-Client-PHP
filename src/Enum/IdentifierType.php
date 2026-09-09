<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * How a decoupled flow identifies the customer with their bank.
 */
final class IdentifierType
{
    public const EMAIL = 'email';
    public const PHONE_NUMBER = 'phone_number';
    public const MOBILE_NUMBER = 'mobile_number';
    public const BANKING_USERNAME = 'banking_username';
    public const CONSENT_ID = 'consent_id';

    private function __construct()
    {
    }
}
