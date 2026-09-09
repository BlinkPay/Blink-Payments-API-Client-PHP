<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * The request never produced an HTTP response: DNS, TCP, TLS or read
 * timeout failure. Distinct from a local validation failure (plain
 * BlinkDebitApiException with status 0) so the client can retry one and not
 * the other. The request may or may not have reached the server, so a
 * money-moving call is only retried when it carries an idempotency key.
 */
class TransportException extends BlinkDebitApiException
{
}
