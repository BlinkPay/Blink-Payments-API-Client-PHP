<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 403: the client is authenticated but lacks the scope, or the resource
 * belongs to another merchant.
 */
class ForbiddenException extends BlinkDebitApiException
{
}
