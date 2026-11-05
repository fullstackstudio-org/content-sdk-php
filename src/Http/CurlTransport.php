<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/**
 * The default transport: ext-curl, no redirects, timeout from the request. On PHP 8.5+ the connections, DNS answers and TLS sessions are kept
 * in a persistent share handle, so a PHP-FPM worker reuses its connection to the Hub across requests (keep-alive) instead of a new TLS
 * handshake per call; that matters for the small personalisation budget. Older PHP: a fresh connection per call, as before.
 */
final class CurlTransport implements Transport
{
    private static mixed $share = null;

    private static function share(): mixed
    {
        if (self::$share === null && function_exists('curl_share_init_persistent')) {
            try {
                self::$share = curl_share_init_persistent([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_SSL_SESSION]);
            } catch (\Throwable) {
                self::$share = false;
            }
        }

        return self::$share ?: null;
    }

    public function send(Request $request): Response
    {
        $handle = curl_init($request->url);

        if ($handle === false) {
            throw new TransportException('Could not start a cURL handle.');
        }

        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($request->timeoutMs, 10_000),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $position = strpos($line, ':');

                if ($position !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }

                return strlen($line);
            },
        ];

        if ($request->multipart !== null) {
            // One encoder for both transports ({@see Request::multipartBody()}): repeated parts and identical bytes on the wire.
            [$contentType, $payload] = $request->multipartBody();
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: ' . $contentType;
            $options[CURLOPT_POSTFIELDS] = $payload;
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if (($share = self::share()) !== null) {
            $options[CURLOPT_SHARE] = $share;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($errno !== 0 || !is_string($body)) {
            // curl_error() can echo parts of the URL, never of the headers; the token only travels in a header.
            $message = curl_error($handle);

            throw new TransportException($message !== '' ? $message : 'cURL error ' . $errno, $errno === CURLE_OPERATION_TIMEDOUT);
        }

        return new Response($status, $responseHeaders, $body);
    }
}
