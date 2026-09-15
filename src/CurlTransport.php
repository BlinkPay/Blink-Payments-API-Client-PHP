<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

use BlinkPay\BlinkDebit\Exception\TransportException;

/**
 * Default transport using ext-curl, so the library carries no runtime
 * Composer dependencies and cannot clash with a platform's pinned HTTP stack.
 */
class CurlTransport implements HttpTransportInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialise the HTTP client.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Response headers are returned inline and split off below, so the
            // client can read Retry-After.
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_SECONDS, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Never follow redirects: a 3xx to another host must not receive the bearer token.
            CURLOPT_FOLLOWLOCATION => false,
            // Refuse TLS 1.0/1.1 even where an old system libcurl would still offer them.
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $curlError = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        if (!is_string($response)) {
            throw new TransportException(sprintf('The Blink Debit API could not be reached: %s', $curlError));
        }

        return [
            'status' => $statusCode,
            'body' => substr($response, $headerSize),
            'headers' => self::parseHeaders(substr($response, 0, $headerSize)),
        ];
    }

    /**
     * Splits a raw header block into a map keyed by lower-case header name.
     *
     * Lines are split on LF and trimmed, because libcurl accepts the bare-LF
     * terminators some origins and proxies still emit as well as CRLF. Status
     * lines carry no colon and are skipped. Where curl emitted more than one
     * block — a 100 Continue or a proxy CONNECT ahead of the real response —
     * the last value for a name wins.
     *
     * @param string $rawHeaders Every header byte curl received, status lines included.
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\n", $rawHeaders) as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $headers;
    }
}
