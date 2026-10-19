<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;
use FullStackStudio\Content\Modules\Admin;
use FullStackStudio\Content\Modules\Appointments;
use FullStackStudio\Content\Modules\Cart;
use FullStackStudio\Content\Modules\Chat;
use FullStackStudio\Content\Modules\Customers;
use FullStackStudio\Content\Modules\Discounts;
use FullStackStudio\Content\Modules\Downloads;
use FullStackStudio\Content\Modules\Events;
use FullStackStudio\Content\Modules\Faq;
use FullStackStudio\Content\Modules\Invoices;
use FullStackStudio\Content\Modules\Legal;
use FullStackStudio\Content\Modules\Members;
use FullStackStudio\Content\Modules\Newsletter;
use FullStackStudio\Content\Modules\Orders;
use FullStackStudio\Content\Modules\Payments;
use FullStackStudio\Content\Modules\Products;
use FullStackStudio\Content\Modules\Returns;
use FullStackStudio\Content\Modules\Reviews;
use FullStackStudio\Content\Modules\Shipping;
use FullStackStudio\Content\Modules\Tickets;

/**
 * The client for `/api/v1` of Fullstack Content (mirror of the JS SDK `client.ts`).
 *
 * - Read routes live directly on the client (`getArticle`, `listPages`, ...); write routes live under `$client->admin`.
 * - Responses are plain associative arrays. Where the API wraps a single object (`{ "article": {...} }`) the object is returned;
 *   lists keep their shape (`articles`, `page`, `limit`, `total`), with `total` also taken from `X-Total-Count`.
 * - Errors are always a {@see ContentException}, also for a network failure or timeout.
 * - Every method takes its call options (`timeoutMs`, `clientIp`, `cache`, `tags`, `ttl`) as the LAST array argument.
 * - With a {@see ClientOptions::$cache} the read calls are cached under tags (see {@see Tags}); writes and `submitForm` never are.
 *
 * ```php
 * $content = ContentClient::create(['token' => getenv('FSC_SERVER_TOKEN')]);
 * $article = $content->getArticle('hello', ['locale' => 'en']);
 * ```
 */
final class ContentClient
{
    public const VERSION = '0.6.0';

    /** Total size of the files of one submission (the API's limit). */
    private const MAX_FORM_FILES_BYTES = 50 * 1024 * 1024;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** Write routes and server-only read routes. Needs a server token with the right scopes. */
    public readonly Admin $admin;

    /** Subscribe, confirm and unsubscribe for the newsletter (`newsletter:subscribe`; a pub token has it by default). */
    public readonly Newsletter $newsletter;

    /** Pricing a cart: discount codes and shipping options (`cart->applyCode`, `cart->shippingOptions`). */
    public readonly Cart $cart;

    /** Products: read with any token, manage with a server token. */
    public readonly Products $products;

    /** Placing, reading and handling orders (server token). */
    public readonly Orders $orders;

    /** Payments of orders, appointments, submissions: start, read, refund (server token). */
    public readonly Payments $payments;

    /** Discount codes and promotions (server token). */
    public readonly Discounts $discounts;

    /** Shipping methods (server token). */
    public readonly Shipping $shipping;

    /** Member accounts: login with two-factor authentication, account flows and member admin (server token only). */
    public readonly Members $members;

    /** The customer file of your customers (server token). */
    public readonly Customers $customers;

    /** Appointments: book as a visitor, manage the agenda with a server token. */
    public readonly Appointments $appointments;

    /** Support requests from visitors and members. */
    public readonly Tickets $tickets;

    /** Invoices (server token), plus the buyer side by pay token. */
    public readonly Invoices $invoices;

    /** Legal documents: read and accept; manage under `legal->documents`. */
    public readonly Legal $legal;

    /** The chatbot: conversations with a per-conversation token. */
    public readonly Chat $chat;

    /** Returns: the buyer requests and follows one; handle them with a server token. */
    public readonly Returns $returns;

    /** Product reviews. */
    public readonly Reviews $reviews;

    /** The FAQ; categories and items under `faq->categories` / `faq->items` (server token). */
    public readonly Faq $faq;

    /** Events: list, register, pay; attendance and check-in with a server token. */
    public readonly Events $events;

    /** Digital products: files and download links. */
    public readonly Downloads $downloads;

    private readonly ModuleContext $context;

    /** @param array<string, mixed>|ClientOptions $options see {@see ClientOptions}; throws {@see ContentException} (`invalid_config`) on misuse */
    public function __construct(array|ClientOptions $options)
    {
        $this->context = new ModuleContext(ClientOptions::from($options));
        $this->admin = new Admin($this->context);
        $this->newsletter = new Newsletter($this->context);
        $this->cart = new Cart($this->context);
        $this->products = new Products($this->context);
        $this->orders = new Orders($this->context);
        $this->payments = new Payments($this->context);
        $this->discounts = new Discounts($this->context);
        $this->shipping = new Shipping($this->context);
        $this->members = new Members($this->context);
        $this->customers = new Customers($this->context);
        $this->appointments = new Appointments($this->context);
        $this->tickets = new Tickets($this->context);
        $this->invoices = new Invoices($this->context);
        $this->legal = new Legal($this->context);
        $this->chat = new Chat($this->context);
        $this->returns = new Returns($this->context);
        $this->reviews = new Reviews($this->context);
        $this->faq = new Faq($this->context);
        $this->events = new Events($this->context);
        $this->downloads = new Downloads($this->context);
    }

    /** @param array<string, mixed>|ClientOptions $options */
    public static function create(array|ClientOptions $options): self
    {
        return new self($options);
    }

    /** The shared request machinery; module classes (and only they) are built on it. @internal */
    public function context(): ModuleContext
    {
        return $this->context;
    }

    /** The base URL without a trailing slash. */
    public function baseUrl(): string
    {
        return $this->context->baseUrl();
    }

    // --- Project, articles, categories ------------------------------------------------------------------------------------

    /**
     * Any token. Name, slug, modules and locales of the project.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getProject(array $options = []): array
    {
        return $this->context->get('/project', [Tags::PROJECT], null, $options)->data;
    }

    /**
     * A pub token only sees published articles. Without `bodyHtml`.
     *
     * @param array{status?: string, category?: string, locale?: string, fallback?: bool, q?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array{articles: list<array<string, mixed>>, page: int, limit: int, total: int}
     */
    public function listArticles(array $params = [], array $options = []): array
    {
        return $this->context->withTotal($this->context->get('/articles', [Tags::ARTICLES], [
            'status' => $params['status'] ?? null,
            'category' => $params['category'] ?? null,
            'locale' => $params['locale'] ?? null,
            'fallback' => ($params['fallback'] ?? false) ? 'true' : null,
            'q' => $params['q'] ?? null,
            'page' => $params['page'] ?? null,
            'limit' => $params['limit'] ?? null,
        ], $options));
    }

    /**
     * By slug or id; with `bodyHtml`. Without `locale`, `nl` wins. Throws a {@see ContentException} with status 404 if it does not exist.
     *
     * @param array{locale?: string, fallback?: bool} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getArticle(string $ref, array $params = [], array $options = []): array
    {
        return $this->context->get('/articles/' . ModuleContext::seg($ref), [Tags::article($ref), Tags::CATEGORIES], [
            'locale' => $params['locale'] ?? null,
            'fallback' => ($params['fallback'] ?? false) ? 'true' : null,
        ], $options)->data['article'] ?? [];
    }

    /**
     * The categories as a tree (`children`, `articleCount`). `$params['locale']` (+ `fallback`) for one language.
     * (Argument order differs from the JS SDK: params first, options last, like every other method.)
     *
     * @param array{locale?: string, fallback?: bool} $params
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function listCategories(array $params = [], array $options = []): array
    {
        return $this->context->get('/categories', [Tags::CATEGORIES], [
            'locale' => $params['locale'] ?? null,
            'fallback' => ($params['fallback'] ?? false) ? 'true' : null,
        ], $options)->data['categories'] ?? [];
    }

    /**
     * All languages of one piece of content (`translationGroupId`); a pub token only sees published ones.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getTranslations(string $groupId, array $options = []): array
    {
        return $this->context->get('/translations/' . ModuleContext::seg($groupId), [Tags::ARTICLES, Tags::PAGES], null, $options)->data;
    }

    // --- Pages -----------------------------------------------------------------------------------------------------------

    /**
     * Without blocks, sorted by path.
     *
     * @param array{status?: string, locale?: string, fallback?: bool, q?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array{pages: list<array<string, mixed>>, page: int, limit: int, total: int}
     */
    public function listPages(array $params = [], array $options = []): array
    {
        return $this->context->withTotal($this->context->get('/pages', [Tags::PAGES], [
            'status' => $params['status'] ?? null,
            'locale' => $params['locale'] ?? null,
            'fallback' => ($params['fallback'] ?? false) ? 'true' : null,
            'q' => $params['q'] ?? null,
            'page' => $params['page'] ?? null,
            'limit' => $params['limit'] ?? null,
        ], $options));
    }

    /**
     * By id or path (`about-us`, `/services/web-design`); with `blocks` and `media`. Throws 404 if it does not exist or is not published yet.
     *
     * @param array{locale?: string, fallback?: bool} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getPage(string $ref, array $params = [], array $options = []): array
    {
        $tags = [Tags::page($ref), Tags::MEDIA];
        $query = ['locale' => $params['locale'] ?? null, 'fallback' => ($params['fallback'] ?? false) ? 'true' : null];

        // An id goes in the path; a path (with slashes) goes via `?path=`, the route that also handles deep paths.
        $reply = preg_match(self::UUID, $ref) === 1
            ? $this->context->get('/pages/' . ModuleContext::seg($ref), $tags, $query, $options)
            : $this->context->get('/pages', $tags, ['path' => '/' . Tags::normalizePagePath($ref), ...$query], $options);

        return $reply->data['page'] ?? [];
    }

    // --- Forms -----------------------------------------------------------------------------------------------------------

    /**
     * The definition of an active form.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getForm(string $key, array $options = []): array
    {
        return $this->context->get('/forms/' . ModuleContext::seg($key), [Tags::form($key)], null, $options)->data['form'] ?? [];
    }

    /**
     * Submits an entry. `$values` is a flat array (field name => value); include the honeypot field (`definition.honeypotField`)
     * unchanged when you build the form yourself: a human leaves it empty. Never cached. Call options besides the common ones:
     * `meta` (array, sent as `_meta`) and `turnstileToken` (sent as `_turnstile`).
     *
     * A value for a `file` field is an `\SplFileInfo` (Laravel/Symfony `UploadedFile` included), an {@see UploadFile}, or a list of them
     * (up to the field's `maxFiles`). With files the request goes as `multipart/form-data` (booleans as "true"/"false", numbers as text,
     * `_meta` as JSON text); without files it is the usual JSON request. Content checks size, type and count (400/413/415) and the whole
     * submission may not exceed 50 MB; files are read into memory, so this SDK refuses more than 50 MB up front.
     *
     * @param array<string, mixed> $values
     * @param array{meta?: array<string, mixed>, turnstileToken?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function submitForm(string $key, array $values, array $options = []): array
    {
        // Like the JS SDK: no value, no part (null and an empty list, e.g. an optional file field without files).
        $body = array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== []);

        if (!empty($options['meta'])) {
            $body['_meta'] = $options['meta'];
        }

        if (!empty($options['turnstileToken'])) {
            $body['_turnstile'] = $options['turnstileToken'];
        }

        // Only with a server token: Content knows a browser's IP itself.
        $ip = $this->context->visitorIp($options['clientIp'] ?? null);

        return $this->context->execute([
            'method' => 'POST',
            'path' => '/forms/' . ModuleContext::seg($key) . '/submissions',
            ...self::formPayload($body),
            'headers' => $ip !== null ? ['X-FSC-Client-Ip' => $ip] : null,
            'options' => $options,
        ])->data;
    }

    /**
     * Downloads a file that a visitor attached to a submission (server token with `forms:read`; the `files[].id` of a submission).
     * Content serves it as an attachment; treat the bytes as untrusted (never echo them inline).
     *
     * @param array<string, mixed> $options
     * @return array{bytes: string, contentType: string, fileName: string|null}
     */
    public function downloadSubmissionFile(string $formKey, string $submissionId, string $fileId, array $options = []): array
    {
        return $this->context->download([
            'path' => '/forms/' . ModuleContext::seg($formKey) . '/submissions/' . ModuleContext::seg($submissionId) . '/files/' . ModuleContext::seg($fileId),
            'options' => $options,
        ]);
    }

    /**
     * The `body` (JSON) or `form` (multipart) part of a submission request.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function formPayload(array $body): array
    {
        $parts = [];
        $total = 0;
        $hasFiles = false;

        foreach ($body as $name => $value) {
            $files = null;

            if ($value instanceof \SplFileInfo || $value instanceof UploadFile) {
                $files = [$value];
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $v): bool => $v instanceof \SplFileInfo || $v instanceof UploadFile) !== []) {
                $files = $value;
            }

            if ($files === null) {
                continue;
            }

            $hasFiles = true;
            $list = [];

            foreach ($files as $file) {
                $upload = self::toUpload($file);
                $total += strlen($upload->contents);

                if ($total > self::MAX_FORM_FILES_BYTES) {
                    throw new ContentException(0, 'invalid_input', 'The files of a submission may not exceed 50 MB together.');
                }

                $list[] = $upload;
            }

            $parts[$name] = count($list) === 1 ? $list[0] : $list;
        }

        if (!$hasFiles) {
            return ['body' => $body];
        }

        foreach ($body as $name => $value) {
            if (isset($parts[$name])) {
                continue;
            }

            if ($name === '_meta') {
                $parts[$name] = Arr::json((array) $value);
            } elseif (is_bool($value)) {
                $parts[$name] = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $parts[$name] = (string) $value;
            } else {
                throw new ContentException(0, 'invalid_input', "The value of `{$name}` cannot be sent as a multipart text part.");
            }
        }

        return ['form' => $parts];
    }

    private static function toUpload(mixed $file): UploadFile
    {
        if ($file instanceof UploadFile) {
            return $file;
        }

        if (!$file instanceof \SplFileInfo || !$file->isFile() || !$file->isReadable()) {
            throw new ContentException(0, 'invalid_input', 'A file for the form is missing or unreadable.');
        }

        if ($file->getSize() > self::MAX_FORM_FILES_BYTES) {
            throw new ContentException(0, 'invalid_input', 'The files of a submission may not exceed 50 MB together.');
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            throw new ContentException(0, 'invalid_input', 'A file for the form could not be read.');
        }

        // Symfony/Laravel UploadedFile: the name and type the visitor's browser sent; a plain file: its basename and what the bytes look like.
        $name = method_exists($file, 'getClientOriginalName') ? (string) $file->getClientOriginalName() : '';
        $type = method_exists($file, 'getClientMimeType') ? (string) $file->getClientMimeType() : '';

        if ($type === '') {
            $detected = function_exists('mime_content_type') ? @mime_content_type($file->getPathname()) : false;
            $type = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        }

        return new UploadFile($contents, $name !== '' ? $name : $file->getBasename(), $type);
    }

    /**
     * Restarts the payment of a submission with a `payment` field (after a failed or expired payment). 409 when it is paid already.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function restartFormPayment(string $key, string $submissionId, array $options = []): array
    {
        return $this->context->execute([
            'method' => 'POST',
            'path' => '/forms/' . ModuleContext::seg($key) . '/submissions/' . ModuleContext::seg($submissionId) . '/payment',
            'options' => $options,
        ])->data['payment'] ?? [];
    }

    // --- Media -----------------------------------------------------------------------------------------------------------

    /**
     * @param array{q?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array{media: list<array<string, mixed>>, page: int, limit: int, total: int}
     */
    public function listMedia(array $params = [], array $options = []): array
    {
        return $this->context->withTotal($this->context->get('/media', [Tags::MEDIA], [
            'q' => $params['q'] ?? null,
            'page' => $params['page'] ?? null,
            'limit' => $params['limit'] ?? null,
        ], $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getMedia(string $id, array $options = []): array
    {
        return $this->context->get('/media/' . ModuleContext::seg($id), [Tags::MEDIA], null, $options)->data['media'] ?? [];
    }

    /** The full URL of the file (`/api/media/{id}`; no token needed, stable and cached as immutable). */
    public function mediaUrl(string $id): string
    {
        return $this->context->baseUrl() . '/api/media/' . ModuleContext::seg($id);
    }

    /** Turns a relative URL from the API (`/api/media/{id}`) into a full URL. */
    public function assetUrl(string $path): string
    {
        return preg_match('#^https?://#i', $path) === 1 ? $path : $this->context->baseUrl() . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    // --- Redirects, SEO, sitemap, search ---------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     * @return array{redirects: list<array<string, mixed>>}
     */
    public function listRedirects(array $options = []): array
    {
        return $this->context->get('/redirects', [Tags::REDIRECTS], null, $options)->data;
    }

    /**
     * Which redirect applies to this path, or null. `location` is the target with any `*` filled in.
     *
     * @param array<string, mixed> $options
     * @return array{location: string, statusCode: int}|null
     */
    public function resolveRedirect(string $path, array $options = []): ?array
    {
        return $this->context->get('/redirects/resolve', [Tags::REDIRECTS], ['path' => $path], $options)->data['redirect'] ?? null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getSeo(array $options = []): array
    {
        return $this->context->get('/seo', [Tags::SEO], null, $options)->data['seo'] ?? [];
    }

    /**
     * URLs of the published content, without origin.
     *
     * @param array{locale?: string} $params
     * @param array<string, mixed> $options
     * @return array{robotsIndex: bool, canonicalOrigin?: string|null, entries: list<array<string, mixed>>}
     */
    public function getSitemap(array $params = [], array $options = []): array
    {
        return $this->context->get('/sitemap', [Tags::SITEMAP], ['locale' => $params['locale'] ?? null], $options)->data;
    }

    /**
     * Search the published articles, pages and active products (module `search`, scope `search:read`; a pub token has it by default).
     * Matches the start of a word, uses the language of each document and the project's synonyms. A term shorter than 2 characters
     * gives an empty result. `kind` may be a list or a comma separated string.
     *
     * @param array{q: string, kind?: string|list<string>, locale?: string, limit?: int, offset?: int} $params
     * @param array{clientIp?: string, timeoutMs?: int, cache?: bool, tags?: list<string>, ttl?: int} $options
     * @return array<string, mixed>
     */
    public function search(array $params, array $options = []): array
    {
        $ip = $this->context->visitorIp($options['clientIp'] ?? null);
        $kind = $params['kind'] ?? null;

        return $this->context->execute([
            'path' => '/search',
            'tags' => [Tags::ARTICLES, Tags::PAGES, Tags::SITEMAP],
            'query' => [
                'q' => $params['q'],
                'kind' => is_array($kind) ? implode(',', $kind) : $kind,
                'locale' => $params['locale'] ?? null,
                'limit' => $params['limit'] ?? null,
                'offset' => $params['offset'] ?? null,
            ],
            'headers' => $ip !== null ? ['X-FSC-Client-Ip' => $ip] : null,
            'options' => $options,
        ])->data;
    }

    // --- Analytics (module `analytics`) ---------------------------------------------------------------------------------------

    /**
     * Any token. The Umami site config: `ready`, `websiteId`, `scriptUrl`, `recorderUrl`, `hostUrl`, `domains`, the binding `consent`
     * rules (pageviews/events without consent, replay/heatmap only after consent) and the `attribution` parameters to keep. Cached for
     * five minutes by default (no webhook event changes it). Throws `ContentException` 404 `module_disabled` when the module is off.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getAnalyticsConfig(array $options = []): array
    {
        return $this->context->get('/analytics', [Tags::ANALYTICS], null, ['ttl' => 300, ...$options])->data['analytics'] ?? [];
    }

    // --- Cookie consent --------------------------------------------------------------------------------------------------

    /**
     * The cookie banner settings (module `privacy`, scope `privacy:consent`): version, texts, policy link and the offered categories.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getConsentConfig(array $options = []): array
    {
        return $this->context->get('/consent', [Tags::PROJECT], null, $options)->data['consent'] ?? [];
    }

    /**
     * Records a visitor's cookie choice as proof of consent (no IP is stored). With a server token pass the visitor's IP via
     * `clientIp` (rate limiting only) and the browser's via `userAgent`.
     *
     * @param array<string, mixed> $input
     * @param array{clientIp?: string, userAgent?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function recordConsent(array $input, array $options = []): array
    {
        $headers = [];
        $ip = $this->context->visitorIp($options['clientIp'] ?? null);

        if ($ip !== null) {
            $headers['X-FSC-Client-Ip'] = $ip;
        }

        if ($this->context->isServerToken() && !empty($options['userAgent'])) {
            $headers['X-FSC-Client-User-Agent'] = substr((string) $options['userAgent'], 0, 200);
        }

        return $this->context->execute([
            'method' => 'POST',
            'path' => '/consent',
            'body' => $input,
            'headers' => $headers === [] ? null : $headers,
            'options' => $options,
        ])->data;
    }
}
