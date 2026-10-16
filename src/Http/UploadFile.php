<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/** A file part of a multipart request. */
final class UploadFile
{
    public function __construct(
        public readonly string $contents,
        public readonly string $filename,
        public readonly string $contentType = 'application/octet-stream',
    ) {
    }
}
