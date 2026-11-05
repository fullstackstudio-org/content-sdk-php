<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The FullStack Intelligence half of the native proxy (`POST {analytics.path}/e`), see `content.intelligence`. OFF unless
 * `content.intelligence.enabled` is true; then:
 *
 * - {@see markExcluded()}: a batch from an excluded IP (office) or from a browser with the staff marker cookie (`staff_cookie`, set by the
 *   admin at staff login on `.fullstackstudio.nl`) gets the property `fsi_ex = "1"` on every event that may carry properties, so the Hub's
 *   scorer skips the visit. Analytics still counts it.
 * - {@see identifyLater()}: after the response, for a batch WITH consent and a `pid` only, one server-to-server call to the Hub
 *   (`identifyVisitor`) with the portal recognition cookie when configured. Cached per browser (and cookie) for `identify_ttl` seconds,
 *   so a visit makes one call, not one per batch. Excluded IPs and staff browsers are never identified (no profile, no recognition). A failure is silent: tracking never breaks.
 * - {@see newsletterClickLater()}: a page view that arrived from a newsletter click link (`?fscn=<token>`, added by the Hub's redirect) of a
 *   CONSENTING browser (the `fsa_pid` cookie, never under `Sec-GPC: 1`, never for crawlers, excluded IPs or staff) makes, after the response,
 *   one call `linkNewsletterClick` per browser and token. fsa.js / fsc.js take `fscn` out of the address bar.
 */
final class IntelligenceProxy
{
    public const EXCLUDE_PROP = 'fsi_ex';

    /** The query parameter of a newsletter click link (Hub `ClickLinks::PARAM`). */
    public const NEWSLETTER_PARAM = 'fscn';

    /** Event types that keep their `p` through the collector (heatmap clicks have none, frustration keeps only its own keys). */
    private const MARKABLE = ['pageview', 'event', 'heartbeat', 'outbound', 'download', 'form_start', 'form_submit', 'scroll', 'scroll_max', 'revenue'];

    public static function enabled(): bool
    {
        return (bool) config('content.intelligence.enabled', false);
    }

    /**
     * The browser id of a CONSENTING browser: the cookie `fsa_pid` that fsa.js mirrors (its presence implies consent), read from the raw
     * `Cookie` header (so Laravel's cookie encryption never matters). Null for a crawler or prefetch, when the browser sends `Sec-GPC: 1`,
     * when the cookie is missing, or when it is not a browser id. Shared by the member helpers and the form controller.
     */
    public static function pid(Request $request): ?string
    {
        if ($request->headers->get('Sec-GPC') === '1' || Crawler::is($request)) {
            return null;
        }

        $pid = SlotDecisions::rawCookies($request)[SlotDecisions::COOKIE] ?? null;

        return is_string($pid) && preg_match('/^[A-Za-z0-9-]{16,64}$/D', $pid) === 1 ? $pid : null;
    }

    public static function isExcluded(?string $ip): bool
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) config('content.intelligence.exclude_ips', '')))));

        return $ip !== null && $list !== [] && IpUtils::checkIp($ip, $list);
    }

    /** A staff browser: the admin's marker cookie is present (its value does not matter; a visitor setting it only opts out). */
    public static function isStaff(Request $request): bool
    {
        $name = (string) config('content.intelligence.staff_cookie', 'fsi_staff');

        return $name !== '' && $request->cookies->has($name);
    }

    public static function markExcluded(stdClass $batch, ?string $ip, ?Request $request = null): void
    {
        if (!self::enabled() || (!self::isExcluded($ip) && ($request === null || !self::isStaff($request)))) {
            return;
        }

        foreach ($batch->e as $event) {
            if ($event instanceof stdClass && in_array($event->t ?? null, self::MARKABLE, true)) {
                $props = isset($event->p) && $event->p instanceof stdClass ? $event->p : new stdClass();
                $props->{self::EXCLUDE_PROP} = '1';
                $event->p = $props;
            }
        }
    }

    public static function identifyLater(Request $request, stdClass $batch, ?string $ip): void
    {
        if (!self::enabled() || ($batch->c ?? 0) !== 1 || !is_string($batch->pid ?? null) || preg_match('/^[A-Za-z0-9-]{16,64}$/D', $batch->pid) !== 1 || self::isExcluded($ip) || self::isStaff($request)) {
            return;
        }

        $pid = $batch->pid;
        $name = (string) config('content.intelligence.recognition_cookie', '');
        $cookie = $name !== '' ? $request->cookies->get($name) : null;
        $cookie = is_string($cookie) && strlen($cookie) <= 200 ? $cookie : null;

        app()->terminating(static function () use ($pid, $cookie): void {
            try {
                $cache = app('cache')->store();
                $key = 'fsi:id:' . hash('sha256', $pid . '|' . ($cookie ?? ''));

                if (!$cache->add($key, 1, max(60, (int) config('content.intelligence.identify_ttl', 43200)))) {
                    return;
                }

                app(ContentClient::class)->identifyVisitor($pid, $cookie, ['timeoutMs' => 1500]);
            } catch (Throwable) {
                // The Hub is away or the module is off: try again after the TTL. Never visible to the visitor.
            }
        });
    }

    /** The `fscn` token of a page view, or null when there is none or it is not shaped like one (68 base64url characters). */
    public static function newsletterToken(Request $request): ?string
    {
        $token = $request->query(self::NEWSLETTER_PARAM);

        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{68}$/D', $token) === 1 ? $token : null;
    }

    /**
     * A GET page view with `fscn` from a consenting browser: after the response, one `linkNewsletterClick` per browser and token (cached 30
     * days, so a reload or a second tab makes no second call). Nothing without consent (no `fsa_pid` cookie), under `Sec-GPC: 1`, for a
     * crawler or prefetch, an excluded IP or a staff browser. Silent on failure.
     */
    public static function newsletterClickLater(Request $request): void
    {
        if (!self::enabled() || !$request->isMethod('GET')) {
            return;
        }

        $token = self::newsletterToken($request);
        $pid = $token === null ? null : self::pid($request);

        if ($token === null || $pid === null || self::isExcluded($request->ip()) || self::isStaff($request)) {
            return;
        }

        app()->terminating(static function () use ($pid, $token): void {
            try {
                if (!app('cache')->store()->add('fsi:nl:' . hash('sha256', $pid . '|' . $token), 1, 30 * 86400)) {
                    return;
                }

                app(ContentClient::class)->linkNewsletterClick($pid, $token, ['timeoutMs' => 1500]);
            } catch (Throwable) {
                // The Hub is away or the module is off: the click still counted anonymously. Never visible to the visitor.
            }
        });
    }
}
