<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use RuntimeException;

/**
 * The marked block that `php artisan fsc:agents` keeps in the site's `AGENTS.md`, and the `@AGENTS.md` import in `CLAUDE.md`.
 * Pure string handling (no I/O), so it is easy to test. Everything outside the markers is left alone.
 */
final class AgentsBlock
{
    public const START = '<!-- fullstack-content:start -->';
    public const END = '<!-- fullstack-content:end -->';
    public const IMPORT_LINE = '@AGENTS.md';

    /** The text between (and including) the markers. */
    public static function block(): string
    {
        return implode("\n", [
            self::START,
            '## Fullstack Content (CMS)',
            '',
            'This site gets its content (pages, articles, forms, SEO, redirects, media) from Fullstack Content, through the PHP SDK `fullstackstudio/content`.',
            '',
            'Before changing anything about content, forms, SEO, sitemap, redirects, caching or the webhook, read `vendor/fullstackstudio/content/AGENTS.md`',
            '(the full integration guide for agents, with copy-paste recipes).',
            '',
            'Key rules:',
            '- Content lives in Content, not in this repo: do not hardcode copy that is managed there. Keep URLs 1:1 with the content (a 404 from Content is a 404 here; a Content outage is a 503, never a stack trace).',
            '- Use the injected `FullStackStudio\Content\ContentClient` (or the `Content` facade). The server token (`FSC_SERVER_TOKEN`) exists only as a runtime environment variable: never in the repo, the image, a view or the browser.',
            '- Forms go through `<x-content::form>` and Content. The site sends no mail and has no SMTP.',
            '- Do not build your own cache or webhook handling: the SDK caches reads under tags and `POST /content/webhook` clears them.',
            '',
            'Managed by `php artisan fsc:agents`. This block is overwritten on every run; put your own notes outside the markers.',
            self::END,
        ]);
    }

    /** Content for a new `AGENTS.md`. */
    public static function newFile(): string
    {
        return "# AGENTS.md\n\n" . self::block() . "\n";
    }

    /**
     * Inserts or replaces the block in existing text. Text outside the markers is untouched; without markers the block is appended.
     *
     * @throws RuntimeException when only one of the two markers is present (the file is left for a human to fix)
     */
    public static function apply(string $existing): string
    {
        $start = strpos($existing, self::START);
        $end = strpos($existing, self::END);

        if ($start === false && $end === false) {
            if (trim($existing) === '') {
                return self::newFile();
            }

            return rtrim($existing) . "\n\n" . self::block() . "\n";
        }

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('AGENTS.md has an incomplete fullstack-content block (a start or end marker is missing). Fix or remove it, then run the command again.');
        }

        return substr($existing, 0, $start) . self::block() . substr($existing, $end + strlen(self::END));
    }

    /** Whether a `CLAUDE.md` already imports `@AGENTS.md` (a line of its own). */
    public static function hasImport(string $claude): bool
    {
        return preg_match('/^@AGENTS\.md[ \t]*$/m', $claude) === 1;
    }

    /** Appends the import line to a `CLAUDE.md` that lacks it. */
    public static function withImport(string $claude): string
    {
        return self::hasImport($claude) ? $claude : rtrim($claude) . "\n\n" . self::IMPORT_LINE . "\n";
    }
}
