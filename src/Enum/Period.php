<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Enduring consent periods. A monthly period from 2019-08-21T00:00:00 runs
 * 2019-08-21T00:00:00 to 2019-09-20T23:59:59.
 */
final class Period
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const FORTNIGHTLY = 'fortnightly';
    public const MONTHLY = 'monthly';
    public const ANNUAL = 'annual';

    private function __construct()
    {
    }
}
