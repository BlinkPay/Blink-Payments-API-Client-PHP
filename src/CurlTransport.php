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

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }

                return strlen($line);
            },
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_SECONDS, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Refuse TLS 1.0/1.1 even where an old system libcurl would still offer them.
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $curlError = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            throw new TransportException(sprintf('The Blink Debit API could not be reached: %s', $curlError));
        }

        return [
            'status' => $statusCode,
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}
