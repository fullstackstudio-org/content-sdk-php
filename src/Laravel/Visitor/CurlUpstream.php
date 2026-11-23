<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

/**
 * The default {@see Upstream}: its own cURL call, not the SDK client (no cache, no JSON handling, a body cap). No redirects are followed,
 * http(s) only, the answer is read in chunks and the transfer is aborted as soon as it passes `maxBytes` (also when `Content-Length` already
 * says so), so one request never holds more than the cap in memory. Files in a multipart body are streamed from disk by cURL.
 */
final class CurlUpstream implements Upstream
{
    public function send(UpstreamRequest $request): UpstreamResponse
    {
        $handle = curl_init($request->url);

        if ($handle === false) {
            throw new VisitorProxyException(VisitorProxyException::UNREACHABLE);
        }

        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        // No `Expect: 100-continue` round trip for a body.
        $headers[] = 'Expect:';

        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        $max = $request->maxBytes;

        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$tooLarge, $max): int {
                // A new status line (after `100 Continue`) starts a new header block.
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];

                    return strlen($line);
                }

                $position = strpos($line, ':');

                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    $responseHeaders[$name][] = $value;

                    if ($name === 'content-length' && ctype_digit($value) && (int) $value > $max) {
                        $tooLarge = true;

                        return 0;
                    }
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($request->multipart !== null) {
            // A field array makes cURL send multipart/form-data with its own boundary and stream every CURLFile from disk.
            $options[CURLOPT_POSTFIELDS] = $request->multipart;
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $options);
        curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($tooLarge) {
            throw new VisitorProxyException(VisitorProxyException::RESPONSE_TOO_LARGE);
        }

        if ($errno !== 0 || $status < 100) {
            // curl_error() is not used: it can echo the URL, and the URL holds the visitor's token.
            throw new VisitorProxyException($errno === CURLE_OPERATION_TIMEDOUT ? VisitorProxyException::TIMEOUT : VisitorProxyException::UNREACHABLE);
        }

        return new UpstreamResponse($status, $responseHeaders, $body);
    }
}
