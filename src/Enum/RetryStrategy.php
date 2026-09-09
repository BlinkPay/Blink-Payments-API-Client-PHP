<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Fixed recurring payment retry strategies. same_day first attempts at 9am NZ
 * time and retries hourly until 10pm.
 */
final class RetryStrategy
{
    public const NONE = 'none';
    public const SAME_DAY = 'same_day';

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }
}
