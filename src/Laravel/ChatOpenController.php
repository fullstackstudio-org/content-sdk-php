<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `POST {content.intelligence.path}/chat` (default `/_fsc/i/chat`): opens a chatbot conversation for the widget on the site's own domain, so the
 * Hub can link the conversation to the visitor's FullStack Intelligence profile and verified identity (plan ootb-integrations D4/D5) WITHOUT the
 * values ever reaching page JavaScript:
 *
 * - `pid` = the consented browser id (`fsa_pid`, {@see IntelligenceProxy::pid()}: never under `Sec-GPC: 1`, never for a crawler);
 * - `recognition` = the HttpOnly recognition cookie ({@see MemberRecognition::cookieName()}), read from the raw `Cookie` header;
 * - neither for an excluded IP or a staff browser (as the tracker proxy never identifies those).
 *
 * The widget opts in with `<fsc-chat token="fsc_pub_…" open-url="/_fsc/i/chat">`; every later call (messages, reads) goes straight to the Hub with
 * the conversation token. The call to the Hub uses the site's server token and passes the visitor IP (per-visitor limits) and an optional
 * `X-FSC-Member-Session` header. Answers are the Hub's own (201 with the conversation token, or its error), never cached.
 *
 * Cross-site use is refused: an `Origin` or `Sec-Fetch-Site` that is not this site gives 403 (the cookies are SameSite=Lax as well). No middleware
 * group: no session, CSRF or cookie encryption.
 */
final class ChatOpenController
{
    private const BODY_MAX_BYTES = 8192;

    public function open(Request $request, ContentClient $content): Response
    {
        if (!self::sameSite($request) || Crawler::is($request)) {
            return self::error(403, 'forbidden', 'Alleen de eigen site mag een gesprek openen.');
        }

        $raw = (string) $request->getContent();
        $body = $raw === '' ? [] : json_decode($raw, true);

        if (strlen($raw) > self::BODY_MAX_BYTES || !is_array($body) || ($body !== [] && array_is_list($body))) {
            return self::error(400, 'invalid_request', 'De body moet een JSON-object zijn.');
        }

        $ip = PersonalizationController::visitorIp($request);
        $identify = IntelligenceProxy::enabled() && !IntelligenceProxy::isExcluded($ip) && !IntelligenceProxy::isStaff($request);
        $name = MemberRecognition::cookieName();
        $recognition = $identify && $name !== '' ? (SlotDecisions::rawCookies($request)[$name] ?? null) : null;
        $member = trim((string) $request->headers->get('X-FSC-Member-Session', ''));

        try {
            $answer = $content->chat->open([
                'locale' => is_string($body['locale'] ?? null) ? substr($body['locale'], 0, 10) : null,
                'turnstileToken' => is_string($body['_turnstile'] ?? null) ? substr($body['_turnstile'], 0, 4096) : null,
                'pid' => $identify ? IntelligenceProxy::pid($request) : null,
                'recognition' => is_string($recognition) && $recognition !== '' && strlen($recognition) <= 200 ? $recognition : null,
            ], array_filter([
                'memberSession' => $member !== '' && strlen($member) <= 200 ? $member : null,
                'clientIp' => $ip,
                'timeoutMs' => 8000,
            ], fn ($value) => $value !== null));
        } catch (ContentException $error) {
            $status = $error->status() >= 400 && $error->status() <= 599 ? $error->status() : 502;

            return self::error($status, $error->status() === 0 ? 'unavailable' : $error->errorCode(), $error->status() === 0 ? 'De chat is nu niet bereikbaar. Probeer het zo opnieuw.' : $error->getMessage(), $error->retryAfter());
        } catch (Throwable) {
            return self::error(502, 'unavailable', 'De chat is nu niet bereikbaar. Probeer het zo opnieuw.');
        }

        return self::json($answer, 201);
    }

    /**
     * Same-origin only: a browser sends `Origin` on a POST and `Sec-Fetch-Site`; either one naming another site is refused. The host is compared
     * without scheme or port: behind Cloudflare and the ingress the request arrives as http while the page is https.
     */
    private static function sameSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if (is_string($site) && $site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }

        $origin = $request->headers->get('Origin');

        if (!is_string($origin) || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === strtolower($request->getHost());
    }

    private static function error(int $status, string $code, string $message, ?int $retryAfter = null): Response
    {
        $response = self::json(['error' => ['code' => $code, 'message' => $message]], $status);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
