<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

/**
 * The Hub connection while `php artisan fsc:doctor` sends sample requests through the app's own HTTP kernel: every request gets the same canned
 * page, so the doctor sees whether the proxy answered (and not a route or middleware of the site) and nothing leaves the process.
 */
final class DoctorUpstream implements Upstream
{
    /** The text of the canned page. */
    public const MARKER = 'fsc-doctor-sample';

    public function send(UpstreamRequest $request): UpstreamResponse
    {
        return new UpstreamResponse(200, ['content-type' => ['text/html; charset=UTF-8']], '<p>' . self::MARKER . '</p>');
    }
}
