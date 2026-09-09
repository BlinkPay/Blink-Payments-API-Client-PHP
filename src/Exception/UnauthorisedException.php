<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 401 after the client has already refreshed its token once: the
 * credentials themselves are rejected.
 */
class UnauthorisedException extends BlinkDebitApiException
{
}
