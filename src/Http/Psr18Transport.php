<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

use Throwable;

/**
 * Optional adapter for any PSR-18 client (Guzzle, Symfony HttpClient, ...). Needs psr/http-client and a PSR-17 request
 * and stream factory; nothing here is type-hinted against PSR so the package still loads without them.
 *
 * The timeout is the client's own (configure it on the client): PSR-18 has no per-request timeout.
 */
final class Psr18Transport implements Transport
{
    /**
     * @param object $client \Psr\Http\Client\ClientInterface
     * @param object $requestFactory \Psr\Http\Message\RequestFactoryInterface
     * @param object $streamFactory \Psr\Http\Message\StreamFactoryInterface
     */
    public function __construct(private readonly object $client, private readonly object $requestFactory, private readonly object $streamFactory)
    {
        if (!interface_exists('Psr\Http\Client\ClientInterface')) {
            throw new \LogicException('Psr18Transport needs psr/http-client (composer require psr/http-client).');
        }
    }

    public function send(Request $request): Response
    {
        $headers = $request->headers;
        $body = $request->body;

        if ($request->multipart !== null) {
            [$headers['Content-Type'], $body] = $request->multipartBody();
        }

        $psr = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        if ($body !== null) {
            $psr = $psr->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($psr);
        } catch (Throwable $error) {
            $timedOut = str_contains(strtolower($error->getMessage()), 'timed out') || str_contains(strtolower($error->getMessage()), 'timeout');

            throw new TransportException('The HTTP client failed.', $timedOut, $error);
        }

        $out = [];

        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', (array) $values);
        }

        return new Response($response->getStatusCode(), $out, (string) $response->getBody());
    }
}
