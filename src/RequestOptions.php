<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Optional per-request headers defined by the Blink Debit API: a caller-chosen
 * request ID and correlation ID for tracing, and the customer's IP address and
 * User-Agent for fraud and velocity checks when the customer is logged in with
 * the merchant.
 *
 * Every value is validated when set, so a value taken from an untrusted source
 * (the customer's browser, say) can never inject a second header line into the
 * authenticated request. Invalid values raise BlinkDebitApiException.
 *
 * Immutable: every with*() call returns a new instance, so a single options
 * object can be shared safely, including from a container-managed client.
 */
final class RequestOptions
{
    private ?string $requestId = null;

    private ?string $correlationId = null;

    private ?string $customerIp = null;

    private ?string $customerUserAgent = null;

    private function __construct()
    {
        // Instances are built through create().
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Overrides the interaction ID Blink Debit would otherwise generate. Must be a UUID.
     *
     * @throws BlinkDebitApiException
     */
    public function withRequestId(string $requestId): self
    {
        $clone = clone $this;
        $clone->requestId = Validation::uuid($requestId, 'request ID');

        return $clone;
    }

    /**
     * Correlation ID for logging a chain of events across systems. Must be a UUID.
     *
     * @throws BlinkDebitApiException
     */
    public function withCorrelationId(string $correlationId): self
    {
        $clone = clone $this;
        $clone->correlationId = Validation::uuid($correlationId, 'correlation ID');

        return $clone;
    }

    /**
     * The customer's IPv4 or IPv6 address, when the customer is currently
     * logged in with the merchant.
     *
     * @throws BlinkDebitApiException
     */
    public function withCustomerIp(string $customerIp): self
    {
        $clone = clone $this;
        $clone->customerIp = Validation::ipAddress($customerIp, 'customer IP');

        return $clone;
    }

    /**
     * The User-Agent of the customer's application or browser.
     *
     * @throws BlinkDebitApiException
     */
    public function withCustomerUserAgent(string $customerUserAgent): self
    {
        $clone = clone $this;
        $clone->customerUserAgent = Validation::headerText($customerUserAgent, 'customer user agent');

        return $clone;
    }

    /**
     * Header lines in the "Name: value" form the transport expects.
     *
     * @param bool $includeCustomerContext Whether to include the customer IP
     *                                     and User-Agent. The API defines those
     *                                     headers only on customer-facing
     *                                     operations; reporting and
     *                                     administrative calls carry tracing
     *                                     headers alone.
     *
     * @return list<string>
     */
    public function toHeaders(bool $includeCustomerContext = true): array
    {
        $candidates = [
            'request-id' => $this->requestId,
            'x-correlation-id' => $this->correlationId,
        ];
        if ($includeCustomerContext) {
            $candidates['x-customer-ip'] = $this->customerIp;
            $candidates['x-customer-user-agent'] = $this->customerUserAgent;
        }

        $headers = [];
        foreach ($candidates as $name => $value) {
            if ($value !== null) {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }
}
