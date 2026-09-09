<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 429 after the client's own bounded retries were exhausted. Back off
 * further before trying again; the idempotency key can be reused.
 */
class RateLimitExceededException extends BlinkDebitApiException
{
}
