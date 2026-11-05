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
 */
final class IntelligenceProxy
{
    public const EXCLUDE_PROP = 'fsi_ex';

    /** Event types that keep their `p` through the collector (heatmap clicks have none, frustration keeps only its own keys). */
    private const MARKABLE = ['pageview', 'event', 'heartbeat', 'outbound', 'download', 'form_start', 'form_submit', 'scroll', 'scroll_max', 'revenue'];

    public static function enabled(): bool
    {
        return (bool) config('content.intelligence.enabled', false);
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
}
