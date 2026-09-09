<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 409: the request clashes with existing state, for example a revoke
 * after payment, a second active schedule on one consent, or one of the
 * idempotency conflicts (BP702, BP703, BP708, BP710, BP712, BP713). Read the
 * BPxxx code from getResponseBody() before deciding whether to retry.
 */
class ConflictException extends BlinkDebitApiException
{
}
