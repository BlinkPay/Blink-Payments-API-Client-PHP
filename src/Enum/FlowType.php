<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Authentication flow types. Gateway flows may carry a redirect or decoupled
 * flow hint.
 */
final class FlowType
{
    public const GATEWAY = 'gateway';
    public const REDIRECT = 'redirect';
    public const DECOUPLED = 'decoupled';

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }
}
