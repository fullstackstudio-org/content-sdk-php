<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Auto\AutoErrors;
use FullStackStudio\Content\Laravel\HubCooldown;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * How the out of the box site reads the Hub: a 404 is an answer ("not there"), everything else is a failure that must stay visible.
 * Never swallows a failure into an empty page.
 */
final class Hub
{
    /**
     * Runs a Hub read under the shared {@see HubCooldown} (refused at once while the Hub was unreachable a moment ago; a timeout or connection
     * error starts it). A {@see ContentException} with status 404 (not found, module off, not published) gives null; anything else is
     * rethrown as is. Pass {@see HubCooldown::options()} to the client call for the short timeout.
     */
    public static function call(callable $read): mixed
    {
        try {
            return HubCooldown::read($read);
        } catch (ContentException $e) {
            if ($e->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Like {@see call()} for code that must produce a response: null or a 404 becomes `abort(404)`, any other failure is reported and becomes
     * `abort(503)` with `Retry-After: 300` and `Cache-Control: no-store` (the Hub is down, the page is not missing; also with
     * `content.auto.errors` off). HTTP exceptions thrown inside the callable pass through.
     */
    public static function orAbort(callable $read): mixed
    {
        try {
            $result = self::call($read);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            // The cooldown's own refusal made no Hub call: the failure that started it was reported already.
            if (! HubCooldown::isRefusal($e)) {
                report($e);
            }

            abort(503, '', ['Retry-After' => (string) AutoErrors::RETRY_AFTER, 'Cache-Control' => 'no-store']);
        }

        return $result ?? abort(404);
    }
}
