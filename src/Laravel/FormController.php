<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Forms;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * The target of `<x-content::form>`: `POST {content.forms.path}/{key}` (route name `content.form`). Validates required fields and lengths like the
 * browser version does, submits with the visitor's IP (`content.forms.ip_header`, default `cf-connecting-ip`, falling back to the request IP) and
 * redirects back with a flash message, the old input and the errors per field. A payment form redirects to the checkout instead.
 */
final class FormController
{
    private const META_LIMIT = 500;

    public function __invoke(Request $request, ContentClient $client, Repository $config, string $key): RedirectResponse
    {
        try {
            $definition = $client->getForm($key);
        } catch (ContentException $e) {
            if ($e->isNotFound()) {
                abort(404);
            }

            return $this->back($request, $key)->with("content_form.{$key}", ['status' => 'error', 'message' => Forms::labels($this->locale($config))['genericError']]);
        }

        $labels = Forms::labels($this->locale($config), (array) $config->get('content.forms.labels', []));
        // A POST over `post_max_size` arrives completely empty (PHP drops body and files): never submit that.
        if ((int) $request->server('CONTENT_LENGTH', 0) > 0 && $request->all() === [] && $request->allFiles() === []) {
            return $this->failed($request, $key, $definition, [], $labels['fileTooLarge']);
        }

        $uploadErrors = Forms::uploadErrors($definition, $request->allFiles());

        if ($uploadErrors !== []) {
            $tooLarge = array_intersect($uploadErrors, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) !== [];

            return $this->failed($request, $key, $definition, [], $tooLarge ? $labels['fileTooLarge'] : $labels['genericError']);
        }

        $values = Forms::values($definition, $request->all(), $request->allFiles());
        $errors = Forms::validate($definition, $values, $labels);
        $turnstile = $this->turnstileToken($request);
        $needsTurnstile = !empty($definition['turnstileSiteKey']);
        $general = null;

        if ($errors === [] && $needsTurnstile && $turnstile === null) {
            $general = $labels['turnstileRequired'];
        }

        if ($errors !== [] || $general !== null) {
            return $this->failed($request, $key, $definition, $errors, $general);
        }

        $options = ['meta' => $this->meta($request, $config)];
        $ip = $this->visitorIp($request, $config);

        if ($ip !== null) {
            $options['clientIp'] = $ip;
        }

        if ($needsTurnstile) {
            $options['turnstileToken'] = $turnstile;
        }

        try {
            $result = $client->submitForm($key, $values, $options);
        } catch (ContentException $e) {
            if ($e->status() === 413) {
                return $this->failed($request, $key, $definition, [], $labels['fileTooLarge']);
            }

            if ($e->status() === 415) {
                return $this->failed($request, $key, $definition, [], $labels['fileType']);
            }

            if ($e->status() === 400) {
                $parsed = Forms::parseFieldErrors($e->getMessage(), $definition['fields'] ?? []);

                return $this->failed($request, $key, $definition, $parsed['fieldErrors'], $parsed['rest'] !== [] ? implode(' ', $parsed['rest']) : ($parsed['fieldErrors'] === [] ? $labels['genericError'] : null));
            }

            return $this->failed($request, $key, $definition, [], $e->isRateLimited() ? $labels['rateLimited'] : $labels['genericError']);
        }

        $checkout = $result['payment']['checkoutUrl'] ?? null;

        if (is_string($checkout) && str_starts_with($checkout, 'https://')) {
            return redirect()->away($checkout);
        }

        return $this->back($request, $key)->with("content_form.{$key}", ['status' => 'success', 'message' => (string) (($result['successMessage'] ?? '') ?: ($definition['successMessage'] ?? ''))]);
    }

    /** @param array<string, mixed> $definition @param array<string, string> $errors */
    private function failed(Request $request, string $key, array $definition, array $errors, ?string $general): RedirectResponse
    {
        // Uploaded files cannot go into the session: drop them from the old input (the visitor picks them again).
        $hidden = ['_token', 'cf-turnstile-response', (string) ($definition['honeypotField'] ?? ''), ...array_keys($request->allFiles())];
        $redirect = $this->back($request, $key)->withInput($request->except($hidden));

        if ($errors !== []) {
            $bag = new ViewErrorBag();
            $bag->put('content_form_' . $key, new MessageBag(array_map(static fn (string $m): array => [$m], $errors)));
            $redirect->with('errors', $bag);
        }

        return $general !== null ? $redirect->with("content_form.{$key}", ['status' => 'error', 'message' => $general]) : $redirect;
    }

    /** Back to the page with the form (same host only), at the form. */
    private function back(Request $request, string $key): RedirectResponse
    {
        $previous = $this->previous($request);

        return redirect()->to(($previous ?? '/') . '#fsc-form-' . $key);
    }

    private function previous(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;

        if (!$parts || ($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        // "//evil.example" or "/\\evil.example" would be read by the browser as another host (open redirect).
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function locale(Repository $config): string
    {
        return (string) ($config->get('content.forms.locale') ?: app()->getLocale());
    }

    private function turnstileToken(Request $request): ?string
    {
        $token = $request->input('cf-turnstile-response');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array<string, string> */
    private function meta(Request $request, Repository $config): array
    {
        $meta = ['locale' => $this->locale($config)];
        $previous = $this->previous($request);

        if ($previous !== null) {
            $meta['page_url'] = $request->getSchemeAndHttpHost() . $previous;
            parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $name) {
                if (isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '') {
                    $meta[$name] = $query[$name];
                }
            }
        }

        // First-touch attribution from the analytics helper (utm_*, ad click ids, landing URL, external referrer) wins over the query of
        // the form page: it is where the visit came from.
        $meta = [...$meta, ...Forms::attributionMeta($request->input('_fsc_attribution'))];

        return array_map(static fn (string $value): string => mb_substr($value, 0, self::META_LIMIT), $meta);
    }

    private function visitorIp(Request $request, Repository $config): ?string
    {
        $header = $config->get('content.forms.ip_header');
        $candidate = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_IP)) {
            return trim($candidate);
        }

        return $request->ip();
    }
}
