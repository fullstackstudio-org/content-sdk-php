<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The target of the `newsletterSignup` block: `POST {content.newsletter.path}` (route name `content.newsletter`, default `content/newsletter`,
 * `web` middleware for session and CSRF). Validates the address, drops a filled honeypot silently, allows `content.newsletter.rate_limit`
 * attempts per visitor IP per minute (default 10), subscribes through `$client->newsletter->subscribe()` with the visitor's IP
 * (`content.newsletter.ip_header`, default `cf-connecting-ip`) and redirects back to the block with a Dutch message. The Hub sends the
 * confirmation mail (double opt-in) and answers the same for a known address, so the message never tells whether someone is subscribed.
 *
 * The list comes from the block (encrypted with the app key by the view), never from free input.
 */
final class NewsletterController
{
    public const MESSAGES = [
        'success' => 'Bijna klaar: we hebben je een e-mail gestuurd. Klik op de link in die mail om je aanmelding te bevestigen.',
        'invalid' => 'Vul een geldig e-mailadres in.',
        'rateLimited' => 'Je hebt het te vaak geprobeerd. Probeer het over een paar minuten opnieuw.',
        'error' => 'Aanmelden lukte niet. Probeer het later opnieuw.',
    ];

    public function __invoke(Request $request, ContentClient $client, Repository $config): RedirectResponse
    {
        $key = $this->blockKey($request->input('block'));
        $ip = $this->visitorIp($request, $config);
        $limiterKey = 'fsc-newsletter:' . sha1((string) $ip);
        $limit = max(1, (int) $config->get('content.newsletter.rate_limit', 10));

        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            return $this->back($request, $key, 'error', self::MESSAGES['rateLimited']);
        }

        RateLimiter::hit($limiterKey, 60);

        // A filled honeypot is a bot: answer like a success, send nothing.
        $honeypot = $request->input('website');

        if (is_string($honeypot) && trim($honeypot) !== '') {
            return $this->back($request, $key, 'success', self::MESSAGES['success']);
        }

        $email = $request->input('email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->back($request, $key, 'error', self::MESSAGES['invalid']);
        }

        $listIds = $this->listIds($request->input('list'));

        if ($listIds === false) {
            return $this->back($request, $key, 'error', self::MESSAGES['error']);
        }

        $options = $ip !== null ? ['clientIp' => $ip] : [];

        // The language of the page the form sat on (hidden field of the block, multilingual projects only): this request is not a page, so
        // the path resolver did not set it. Only an enabled language counts; the client sends it as `locale` on subscribe.
        $posted = $request->input(FormController::LOCALE_FIELD);

        if (is_string($posted) && in_array($posted, $client->enabledLocales(), true)) {
            $client = $client->withLocale($posted);
        }

        try {
            $client->newsletter->subscribe(['email' => $email, 'listIds' => $listIds], $options);
        } catch (ContentException $e) {
            if ($e->isRateLimited()) {
                return $this->back($request, $key, 'error', self::MESSAGES['rateLimited']);
            }

            return $this->back($request, $key, 'error', $e->status() === 400 ? self::MESSAGES['invalid'] : self::MESSAGES['error']);
        } catch (Throwable) {
            return $this->back($request, $key, 'error', self::MESSAGES['error']);
        }

        return $this->back($request, $key, 'success', self::MESSAGES['success']);
    }

    /**
     * The block's list as `[id]`, null for the default list, false when the value was tampered with.
     *
     * @return list<string>|null|false
     */
    private function listIds(mixed $value): array|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || strlen($value) > 1000) {
            return false;
        }

        try {
            $id = Crypt::decryptString($value);
        } catch (DecryptException) {
            return false;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) === 1 ? [$id] : false;
    }

    private function blockKey(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) === 1 ? $value : 'b0';
    }

    /** Back to the page with the block (same host only), at the block, with the message for that block. */
    private function back(Request $request, string $key, string $status, string $message): RedirectResponse
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;
        $path = '/';

        if (is_array($parts) && ($parts['host'] ?? null) === $request->getHost()) {
            $candidate = $parts['path'] ?? '/';

            // "//evil.example" or "/\\evil.example" would be read by the browser as another host (open redirect).
            if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//') && !str_contains($candidate, '\\')) {
                $path = $candidate . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
        }

        return redirect()->to($path . '#fsc-newsletter-' . $key)->with('content_newsletter.' . $key, ['status' => $status, 'message' => $message]);
    }

    private function visitorIp(Request $request, Repository $config): ?string
    {
        $header = $config->get('content.newsletter.ip_header');
        $candidate = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_IP)) {
            return trim($candidate);
        }

        return $request->ip();
    }
}
