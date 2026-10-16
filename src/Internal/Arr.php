<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Internal;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\UploadFile;

/** Small array helpers for the module classes. */
final class Arr
{
    /**
     * Only the listed keys that are present (a `null` value stays: it can mean "clear this field").
     *
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function pick(array $input, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * Without the `null` values (JavaScript drops `undefined`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function defined(array $input): array
    {
        return array_filter($input, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * JSON as the API wants it for a multipart `data` part.
     *
     * @param array<string, mixed> $body
     */
    public static function json(array $body): string
    {
        return json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * The `body` or `form` part of a spec: JSON, or multipart (`data` + repeated `files`) when there are files.
     *
     * @param array<string, mixed> $body
     * @param mixed $files list<UploadFile>|null
     * @return array<string, mixed>
     */
    public static function withFiles(array $body, mixed $files): array
    {
        if (!is_array($files) || $files === []) {
            return ['body' => $body];
        }

        $parts = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadFile) {
                throw new ContentException(0, 'invalid_input', 'Every file must be an UploadFile.');
            }

            $parts[] = $file;
        }

        return ['form' => ['data' => self::json($body), 'files' => $parts]];
    }
}
