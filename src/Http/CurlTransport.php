<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/** The default transport: ext-curl, no redirects, timeout from the request. */
final class CurlTransport implements Transport
{
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
