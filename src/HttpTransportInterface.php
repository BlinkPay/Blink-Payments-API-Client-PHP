<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Minimal HTTP transport abstraction so the client can be unit tested and
 * applications can substitute their own HTTP layer: {@see CurlTransport} by
 * default, {@see \BlinkPay\BlinkDebit\Psr\Psr18Transport} for any PSR-18
 * client (Guzzle, Symfony HttpClient), or a bespoke implementation over a
 * platform HTTP API (e.g. WordPress).
 */
interface HttpTransportInterface
{
    /**
     * Sends an HTTP request and returns the raw response.
     *
     * @param non-empty-string $method         HTTP method.
     * @param string           $url            Absolute URL.
     * @param list<string>     $headers        Header lines, e.g. "Accept: application/json".
     * @param string|null      $body           Raw request body, if any.
     * @param int              $timeoutSeconds Total request timeout.
     *
     * @return array{status: int, body: string, headers?: array<string, string>} Response headers, when the
     *                                                                            transport can supply them, are
     *                                                                            keyed by lower-case name; the
     *                                                                            client reads Retry-After from
     *                                                                            them.
     *
     * @throws \BlinkPay\BlinkDebit\Exception\TransportException When no HTTP response was received at all.
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
