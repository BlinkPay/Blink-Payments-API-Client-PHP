<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 5xx after the client's own bounded retries were exhausted. A create
 * request with an idempotency key may be replayed safely with the same key;
 * poll the resource first where an ID is known.
 */
class ServerErrorException extends BlinkDebitApiException
{
}
