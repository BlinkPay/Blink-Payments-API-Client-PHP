<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Psr;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\HttpTransportInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 HTTP client (Guzzle 7, Symfony HttpClient's
 * Psr18Client, php-http clients, …) so an application can reuse its own HTTP
 * stack — proxies, logging middleware, retry policies, test doubles.
 *
 * PSR-18 only defines how to send a PSR-7 request, not how to build one, so
 * PSR-17 factories are needed too. Symfony's Psr18Client and Guzzle's
 * HttpFactory implement both factory interfaces; pass the same object twice.
 *
 * Requires psr/http-client, psr/http-factory and psr/http-message, which are
 * deliberately not runtime dependencies of this library: install them (and an
 * implementation) in the application when this transport is used.
 */
class Psr18Transport implements HttpTransportInterface
{
    private ClientInterface $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $request = $request->withAddedHeader(trim($pair[0]), trim($pair[1]));
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        // PSR-18 has no per-request timeout; configure it on the underlying
        // client. The interface still receives it so a bespoke transport can
        // honour it.
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new BlinkDebitApiException(
                sprintf('The Blink Debit API could not be reached: %s', $exception->getMessage()),
                0,
                null
            );
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }
}
