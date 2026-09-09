<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * The consent was not authorised in time: the bank or gateway timed it out,
 * or the caller's own wait budget ran out. Thrown by the await helpers, which
 * revoke an abandoned quick payment or enduring consent first.
 */
class ConsentTimeoutException extends BlinkDebitApiException
{
}
