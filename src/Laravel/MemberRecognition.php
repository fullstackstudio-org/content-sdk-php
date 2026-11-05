<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Member recognition on the site's own domain (FullStack Intelligence, plan D8). When a project has the Intelligence module on with its
 * `recognition` opt-in, the success answers of the member login (`login`, `loginTotp`, `refreshSession`) carry
 * `recognition: {cookie, value, maxAge}` and `logout` / `logoutAll` carry `recognition: null`. This class turns that into the cookie
 * `content.intelligence.recognition_cookie` (default `fsi_rec`): HttpOnly, Secure on https (request or `app.url`), SameSite=Lax, path `/`,
 * lifetime `maxAge`, domain `content.intelligence.recognition_cookie_domain` (empty = this host). The proxy and the server-side slots send
 * it to the Hub, which verifies its signature per project.
 *
 * ```php
 * $answer = $content->members->login($credentials, ['clientIp' => $request->ip(), 'pid' => MemberRecognition::pid($request)]);
 * MemberRecognition::queue($answer, $request);   // after login, refreshSession, logout and logoutAll
 * ```
 *
 * Everything is fail-soft: an answer without `recognition`, a crawler, an empty cookie name or a malformed value gives no cookie, and a login
 * never fails because of this. Sites must name recognition in their privacy statement (see AGENTS.md).
 */
final class MemberRecognition
{
    public const DEFAULT_COOKIE = 'fsi_rec';

    /** Longest lifetime accepted from an answer (the Hub issues 180 days). */
    private const MAX_AGE = 34_560_000;

    /** The consenting browser's id (cookie `fsa_pid`), or null (no cookie, `Sec-GPC: 1`, crawler, malformed). Pass it as `['pid' => ...]` to the member calls. */
    public static function pid(Request $request): ?string
    {
        return IntelligenceProxy::pid($request);
    }

    /** The cookie name in use; empty = recognition cookie switched off by the site. */
    public static function cookieName(): string
    {
        $name = config('content.intelligence.recognition_cookie', self::DEFAULT_COOKIE);

        return is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name) === 1 ? $name : '';
    }

    /**
     * The cookie for an answer with `recognition: {cookie, value, maxAge}`; null when `recognition` is absent or null, malformed, the cookie name
     * is switched off, or the request is a crawler.
     *
     * @param array<string, mixed> $answer
     */
    public static function cookie(array $answer, ?Request $request = null): ?Cookie
    {
        $recognition = $answer['recognition'] ?? null;
        $name = self::cookieName();

        if ($name === '' || !is_array($recognition) || ($request !== null && Crawler::is($request))) {
            return null;
        }

        $value = $recognition['value'] ?? null;
        $maxAge = $recognition['maxAge'] ?? null;

        if (!is_string($value) || preg_match('/^[A-Za-z0-9._~-]{1,200}$/D', $value) !== 1 || !is_int($maxAge) || $maxAge < 1) {
            return null;
        }

        return self::build($name, $value, time() + min($maxAge, self::MAX_AGE), $request);
    }

    /** The cookie that removes the recognition (logout). */
    public static function forget(?Request $request = null): Cookie
    {
        return self::build(self::cookieName() ?: self::DEFAULT_COOKIE, '', 1, $request);
    }

    /**
     * What an answer asks for: the cookie to set (`recognition` is an object), the cookie that removes it (`recognition` is null, as on logout),
     * or null (no `recognition` key: the module or the opt-in is off, nothing to do).
     *
     * @param array<string, mixed> $answer
     */
    public static function fromAnswer(array $answer, ?Request $request = null): ?Cookie
    {
        if (!array_key_exists('recognition', $answer) || self::cookieName() === '' || ($request !== null && Crawler::is($request))) {
            return null;
        }

        return $answer['recognition'] === null ? self::forget($request) : self::cookie($answer, $request);
    }

    /** Queues {@see fromAnswer()} on the response (Laravel's cookie jar). Never throws. @param array<string, mixed> $answer */
    public static function queue(array $answer, ?Request $request = null): void
    {
        try {
            $cookie = self::fromAnswer($answer, $request);

            if ($cookie !== null) {
                app('cookie')->queue($cookie);
            }
        } catch (Throwable) {
            // A login never fails because of the recognition cookie.
        }
    }

    private static function build(string $name, string $value, int $expires, ?Request $request): Cookie
    {
        $secure = ($request?->isSecure() ?? false) || str_starts_with((string) config('app.url', ''), 'https://');
        $domain = trim((string) config('content.intelligence.recognition_cookie_domain', ''));

        return new Cookie($name, $value, $expires, '/', $domain !== '' ? $domain : null, $secure, true, false, Cookie::SAMESITE_LAX);
    }
}
