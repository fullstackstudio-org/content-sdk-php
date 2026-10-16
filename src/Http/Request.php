<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/** An outgoing request. `$body` is a JSON string; `$multipart` (field => string|UploadFile) replaces it for uploads. */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|UploadFile>|null $multipart
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly ?array $multipart = null,
        public readonly int $timeoutMs = 10_000,
    ) {
    }

    /**
     * Builds a multipart/form-data body by hand, for transports that cannot do it themselves.
     *
     * @return array{0: string, 1: string} [content-type header value, body]
     */
    public function multipartBody(): array
    {
        $boundary = '----fsc' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($this->multipart ?? [] as $name => $value) {
            $name = str_replace(['"', "\r", "\n"], '', (string) $name);
            $body .= "--{$boundary}\r\n";

            if ($value instanceof UploadFile) {
                $filename = str_replace(['"', "\r", "\n"], '', $value->filename);
                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$value->contentType}\r\n\r\n{$value->contents}\r\n";
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
            }
        }

        return ["multipart/form-data; boundary={$boundary}", $body . "--{$boundary}--\r\n"];
    }
}
