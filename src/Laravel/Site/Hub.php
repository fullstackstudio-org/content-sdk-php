<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * How the out of the box site reads the Hub: a 404 is an answer ("not there"), everything else is a failure that must stay visible.
 * Never swallows a failure into an empty page.
 */
final class Hub
{
    /** Runs a Hub read. A {@see ContentException} with status 404 (not found, module off, not published) gives null; anything else is rethrown as is. */
    public static function call(callable $read): mixed
    {
        try {
            return $read();
        } catch (ContentException $e) {
            if ($e->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Like {@see call()} for code that must produce a response: null or a 404 becomes `abort(404)`, any other failure is reported and becomes
     * `abort(503)` (the Hub is down, the page is not missing). HTTP exceptions thrown inside the callable pass through.
     */
    public static function orAbort(callable $read): mixed
    {
        try {
            $result = self::call($read);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            abort(503);
        }

        return $result ?? abort(404);
    }
}
