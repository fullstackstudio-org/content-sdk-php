<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/**
 * An outgoing request. `$body` is a JSON string; `$multipart` (field => string|UploadFile) replaces it for uploads.
 * A field may also be a LIST of strings/files: the part is then repeated under the same name (`files` with several attachments).
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|UploadFile|list<string|UploadFile>>|null $multipart
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

    /** Some field holds a list (a repeated part): cURL's field array cannot express that. */
    public function hasRepeatedParts(): bool
    {
        foreach ($this->multipart ?? [] as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
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
            $name = self::headerParam((string) $name);

            foreach (is_array($value) ? $value : [$value] as $part) {
                $body .= "--{$boundary}\r\n";

                if ($part instanceof UploadFile) {
                    $filename = self::headerParam($part->filename);
                    $type = preg_match('#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*/[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*$#', $part->contentType) === 1 ? $part->contentType : 'application/octet-stream';
                    $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                    $body .= "Content-Type: {$type}\r\n\r\n{$part->contents}\r\n";
                } else {
                    $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$part}\r\n";
                }
            }
        }

        return ["multipart/form-data; boundary={$boundary}", $body . "--{$boundary}--\r\n"];
    }

    /** A value for `name="..."` / `filename="..."`: no quote, backslash or control character can end the parameter or the header line. */
    private static function headerParam(string $value): string
    {
        return (string) preg_replace('/["\\\\\x00-\x1F\x7F]/', '', $value);
    }
}
