<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\StrictHtml;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The data behind the default views of the page builder's module blocks (SDK 0.28): `eventList`, `event`, `appointmentBooking`, `faq`,
 * `productList` and `newsletterSignup`. Every method reads through the (tag cached) client and returns null when there is nothing to show:
 * the module is off, the Hub cannot be reached, the referenced item is gone or the list is empty. A failure is logged once per block type
 * and reason per process, so a page with a broken block never breaks and never floods the log.
 *
 * Links are site knowledge (`content.links.*`); without them the blocks fall back to the Hub hosted pages (`registrationUrl`,
 * `bookingPageUrl`). Everything returned is plain data: the views escape it, only the Hub sanitized `descriptionHtml` / `answerHtml` is
 * printed unescaped.
 *
 * @internal used by the `content::blocks.*` views of the module blocks
 */
final class ModuleBlockData
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** A same-site path template: starts with one `/`, no scheme, no backslash, no whitespace or quotes. */
    private const PATH = '#^/(?!/)[^\s"\'<>\\\\]*$#D';

    private const HUB_URL = '#^https://[^\s"\'<>\\\\]+$#D';

    /**
     * Seconds the event reads are cached: shorter than the client's default, because the sales state (open, sold out, closed) changes with
     * time and with registrations even when no webhook arrives.
     */
    public const EVENTS_TTL = 300;

    /** @var array<string, true> type + reason pairs already logged in this process */
    private static array $logged = [];

    /**
     * Upcoming published events for an `eventList` block: `[{title, date, time, location, price, href, state}]`, at most `limit` (1 to 12,
     * default 6). An empty list returns `[]` (the view prints `emptyText` when set); null on failure.
     *
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>|null
     */
    public static function eventList(array $block): ?array
    {
        $limit = self::int($block['limit'] ?? null, 1, 12, 6);

        try {
            $list = app(ContentClient::class)->events->list(['upcoming' => true, 'status' => 'published', 'limit' => $limit], ['cache' => true, 'ttl' => self::EVENTS_TTL]);
        } catch (Throwable $e) {
            return self::failed('eventList', $e);
        }

        $events = [];

        foreach (array_slice(is_array($list['events'] ?? null) ? $list['events'] : [], 0, $limit) as $event) {
            $view = is_array($event) ? self::eventView($event) : null;

            if ($view !== null) {
                $events[] = $view;
            }
        }

        return $events;
    }

    /**
     * One published event for an `event` block (`eventRef` = its uuid): `{title, date, time, location, price, href, state, summary,
     * descriptionHtml}`. Null when the reference is malformed, the event is gone, not published, or on any failure.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    public static function event(array $block): ?array
    {
        $ref = $block['eventRef'] ?? null;

        if (!is_string($ref) || preg_match(self::UUID, $ref) !== 1) {
            return null;
        }

        try {
            $event = app(ContentClient::class)->events->get($ref, ['cache' => true, 'ttl' => self::EVENTS_TTL]);
        } catch (Throwable $e) {
            // A deleted event is an expected state, not an error worth a log line.
            return $e instanceof ContentException && $e->isNotFound() && !$e->isModuleDisabled() ? null : self::failed('event', $e);
        }

        return is_array($event) && $event !== [] ? self::eventView($event) : null;
    }

    /**
     * The button of an `appointmentBooking` block: `{href}`. The site route `content.links.booking` wins (`{service}` is replaced by the
     * block's service id, or `?service=<id>` is added); else the Hub booking page (`bookingPageUrl`, plus `/<serviceId>`). Null when neither
     * exists, when the chosen service is no longer active, or on failure.
     *
     * @param array<string, mixed> $block
     * @return array{href: string}|null
     */
    public static function appointmentBooking(array $block): ?array
    {
        $serviceId = is_string($block['serviceId'] ?? null) && preg_match(self::UUID, $block['serviceId']) === 1 ? strtolower($block['serviceId']) : null;

        try {
            $appointments = app(ContentClient::class)->appointments;
            $services = $appointments->services();
            $hubPage = $appointments->bookingPageUrl();
        } catch (Throwable $e) {
            return self::failed('appointmentBooking', $e);
        }

        // A server token also sees inactive services (with `active: false`): only bookable ones count.
        $ids = [];

        foreach ($services as $service) {
            if (is_array($service) && is_string($service['id'] ?? null) && ($service['active'] ?? true) !== false) {
                $ids[] = strtolower($service['id']);
            }
        }

        if ($ids === [] || ($serviceId !== null && !in_array($serviceId, $ids, true))) {
            return null;
        }

        $site = self::template('booking');

        if ($site !== null) {
            if (str_contains($site, '{service}')) {
                $href = $serviceId !== null ? str_replace('{service}', rawurlencode($serviceId), $site) : (rtrim(str_replace(['/{service}', '{service}'], '', $site), '/') ?: '/');
            } else {
                $href = $serviceId !== null ? $site . (str_contains($site, '?') ? '&' : '?') . 'service=' . rawurlencode($serviceId) : $site;
            }

            return ['href' => $href];
        }

        if ($hubPage === null) {
            return null;
        }

        return ['href' => $serviceId !== null ? rtrim($hubPage, '/') . '/' . rawurlencode($serviceId) : $hubPage];
    }

    /**
     * The published questions of a `faq` block: `{items: [{question, answerHtml, answerText}], jsonLd: ?string}`, at most `limit` (1 to 50,
     * default 10), optionally of one category (`categoryId` = its slug). `jsonLd` is the encoded FAQPage when `structuredData` is on (the
     * default). Null when empty, on an unknown category or on failure.
     *
     * @param array<string, mixed> $block
     * @return array{items: list<array{question: string, answerHtml: string, answerText: string}>, jsonLd: ?string}|null
     */
    public static function faq(array $block): ?array
    {
        $limit = self::int($block['limit'] ?? null, 1, 50, 10);
        $category = is_string($block['categoryId'] ?? null) && preg_match(self::SLUG, $block['categoryId']) === 1 ? $block['categoryId'] : null;

        try {
            $faq = app(ContentClient::class)->faq->list(['category' => $category]);
        } catch (Throwable $e) {
            return $e instanceof ContentException && $e->isNotFound() && !$e->isModuleDisabled() ? null : self::failed('faq', $e);
        }

        $items = [];

        foreach (is_array($faq['items'] ?? null) ? $faq['items'] : [] as $item) {
            if (!is_array($item) || !is_string($item['question'] ?? null) || trim($item['question']) === '' || !is_string($item['answerHtml'] ?? null)) {
                continue;
            }

            $items[] = [
                'question' => $item['question'],
                'answerHtml' => $item['answerHtml'],
                'answerText' => is_string($item['answerText'] ?? null) ? $item['answerText'] : trim(strip_tags($item['answerHtml'])),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        $jsonLd = null;

        if (($block['structuredData'] ?? true) !== false) {
            $encoded = json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answerText'] !== '' ? $item['answerText'] : $item['question']],
                ], $items),
            ], \FullStackStudio\Content\Site::JSON_FLAGS);
            $jsonLd = $encoded === false ? null : $encoded;
        }

        return ['items' => $items, 'jsonLd' => $jsonLd];
    }

    /**
     * Active products for a `productList` block: `[{name, summary, cover, price, href}]`, at most `limit` (1 to 12, default 4), optionally of
     * one category (`category` = its slug). The link: `content.links.product` when set, else the path the Hub publishes in its sitemap (the
     * products `pathTemplate`), else `/producten/{slug}`. Null when empty or on failure.
     *
     * @param array<string, mixed> $block
     * @return list<array{name: string, summary: string, cover: ?array<string, mixed>, price: string, href: string}>|null
     */
    public static function productList(array $block): ?array
    {
        $limit = self::int($block['limit'] ?? null, 1, 12, 4);
        $category = is_string($block['category'] ?? null) && preg_match(self::SLUG, $block['category']) === 1 ? $block['category'] : null;

        try {
            $client = app(ContentClient::class);
            $list = $client->products->list(['category' => $category, 'status' => 'active', 'limit' => $limit]);
        } catch (Throwable $e) {
            return self::failed('productList', $e);
        }

        $template = self::template('product');
        $paths = $template === null ? self::productPaths($client) : [];
        $cards = [];

        foreach (array_slice(is_array($list['products'] ?? null) ? $list['products'] : [], 0, $limit) as $product) {
            $slug = is_array($product) ? ($product['slug'] ?? null) : null;

            if (!is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || (($product['status'] ?? 'active') !== 'active')) {
                continue;
            }

            $from = is_int($product['priceFromCents'] ?? null) ? $product['priceFromCents'] : null;
            $to = is_int($product['priceToCents'] ?? null) ? $product['priceToCents'] : null;
            $currency = is_string($product['currency'] ?? null) ? $product['currency'] : 'EUR';
            $cards[] = [
                'name' => (string) ($product['name'] ?? ''),
                'summary' => is_string($product['summary'] ?? null) ? $product['summary'] : '',
                'cover' => is_array($product['cover'] ?? null) ? $product['cover'] : null,
                'price' => $from === null ? '' : (($to !== null && $to !== $from) ? 'Vanaf ' : '') . self::money($from, $currency),
                'href' => $template !== null ? str_replace('{slug}', rawurlencode($slug), $template) : ($paths[$slug] ?? '/producten/' . rawurlencode($slug)),
            ];
        }

        return $cards === [] ? null : $cards;
    }

    /**
     * The form of a `newsletterSignup` block: `{action, anchor, list, flash, preview}`. `list` is the block's list id, encrypted with the app
     * key so a visitor cannot point the form at another list. Null when the newsletter route is not registered (`content.newsletter.enabled`
     * off and no `Content::newsletterRoutes()`), so the block never shows a form that cannot be sent.
     *
     * @param array<string, mixed> $block
     * @return array{action: string, anchor: string, key: string, list: string, flash: ?array<string, mixed>, preview: bool}|null
     */
    public static function newsletterSignup(array $block, int $index): ?array
    {
        if (!Route::has('content.newsletter')) {
            self::log('newsletterSignup', 'route', 'info', 'The newsletter route is not registered: the newsletterSignup block renders nothing.');

            return null;
        }

        $key = self::blockKey($block, $index);
        $listId = is_string($block['listId'] ?? null) && preg_match(self::UUID, $block['listId']) === 1 ? strtolower($block['listId']) : '';
        $flash = null;

        try {
            $flash = session()->get('content_newsletter.' . $key);
        } catch (Throwable) {
            // No session (a stateless route): no message to show.
        }

        return [
            'action' => route('content.newsletter', [], false),
            'anchor' => 'fsc-newsletter-' . $key,
            'key' => $key,
            'list' => $listId === '' ? '' : Crypt::encryptString($listId),
            'flash' => is_array($flash) ? $flash : null,
            'preview' => Preview::isActive(),
        ];
    }

    /** The key a block's flash message and anchor use: its id when it is a plain token, else its index. */
    public static function blockKey(array $block, int $index): string
    {
        $id = $block['id'] ?? null;

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1 ? $id : 'b' . $index;
    }

    /** The visitor-facing state of an event: `open`, `sold_out`, `not_started`, `ended`, `past` or `cancelled`. */
    public static function stateLabel(string $state): ?string
    {
        return match ($state) {
            'sold_out' => 'Uitverkocht',
            'not_started' => 'Aanmelden kan binnenkort',
            'ended' => 'Aanmelden is gesloten',
            'past' => 'Afgelopen',
            'cancelled' => 'Gaat niet door',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private static function eventView(array $event): ?array
    {
        $slug = $event['slug'] ?? null;
        $title = $event['title'] ?? null;

        // A server token also sees drafts and cancelled events: only published ones are shown.
        if (!is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || !is_string($title) || (isset($event['status']) && $event['status'] !== 'published')) {
            return null;
        }

        $state = is_string($event['salesState'] ?? null) ? $event['salesState'] : 'open';
        $starts = is_string($event['startsAt'] ?? null) ? $event['startsAt'] : null;
        $template = self::template('event');
        $hub = is_string($event['registrationUrl'] ?? null) && preg_match(self::HUB_URL, $event['registrationUrl']) === 1 ? $event['registrationUrl'] : null;
        $id = is_string($event['id'] ?? null) ? $event['id'] : '';

        return [
            'title' => $title,
            'startsAt' => $starts,
            'date' => self::localDate($starts, 'dddd D MMMM YYYY'),
            'time' => self::time($starts, is_string($event['endsAt'] ?? null) ? $event['endsAt'] : null),
            'location' => is_string($event['location'] ?? null) ? $event['location'] : '',
            'price' => self::eventPrice($event),
            'href' => $template !== null ? str_replace(['{slug}', '{id}'], [rawurlencode($slug), rawurlencode($id)], $template) : $hub,
            'external' => $template === null && $hub !== null,
            'state' => $state,
            'summary' => is_string($event['summary'] ?? null) ? $event['summary'] : '',
            // Defence in depth: the strict allowlist again, even though the Hub sanitizes (it once served this field raw).
            'descriptionHtml' => is_string($event['descriptionHtml'] ?? null) ? StrictHtml::sanitize($event['descriptionHtml']) : '',
        ];
    }

    /** "Gratis", "€ 12,50" or "Vanaf € 12,50" from the active ticket types. @param array<string, mixed> $event */
    private static function eventPrice(array $event): string
    {
        $prices = [];

        foreach (is_array($event['ticketTypes'] ?? null) ? $event['ticketTypes'] : [] as $type) {
            // A server token also sees inactive ticket types (`active: false`): they cannot be bought, so they do not set the price.
            if (is_array($type) && is_int($type['priceCents'] ?? null) && ($type['active'] ?? true) !== false) {
                $prices[] = $type['priceCents'];
            }
        }

        if ($prices === [] || max($prices) === 0) {
            return 'Gratis';
        }

        $currency = is_string($event['currency'] ?? null) ? $event['currency'] : 'EUR';
        $low = min($prices);

        return $low === 0 ? 'Gratis of vanaf ' . self::money(min(array_filter($prices)), $currency) : (count(array_unique($prices)) > 1 ? 'Vanaf ' : '') . self::money($low, $currency);
    }

    private static function money(int $cents, string $currency): string
    {
        $amount = number_format($cents / 100, 2, ',', '.');

        return (strtoupper($currency) === 'EUR' ? '€ ' : strtoupper($currency) . ' ') . $amount;
    }

    /** A date in the page locale and the site's timezone (`content.timezone`, default Europe/Amsterdam); '' when it cannot be parsed. */
    public static function localDate(?string $iso, string $format = 'D MMMM YYYY'): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        try {
            return Carbon::parse($iso)->setTimezone(self::timezone())->locale(app()->getLocale() ?: 'nl')->isoFormat($format);
        } catch (Throwable) {
            return '';
        }
    }

    /** "19:30" or "19:30 - 21:00" (same day) in the site's timezone. */
    private static function time(?string $starts, ?string $ends): string
    {
        if ($starts === null) {
            return '';
        }

        try {
            $from = Carbon::parse($starts)->setTimezone(self::timezone());
            $out = $from->format('H:i');

            if ($ends !== null) {
                $to = Carbon::parse($ends)->setTimezone(self::timezone());

                if ($to->isSameDay($from) && $to->greaterThan($from)) {
                    $out .= ' - ' . $to->format('H:i');
                }
            }

            return $out;
        } catch (Throwable) {
            return '';
        }
    }

    private static function timezone(): string
    {
        $zone = config('content.timezone');

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Amsterdam';
    }

    /**
     * A same-site link template from `content.links.<name>`, or null when it is not set or not a plain path (a full URL or `//host` is refused,
     * so a typo can never send visitors to another site).
     */
    private static function template(string $name): ?string
    {
        $value = config('content.links.' . $name);

        return is_string($value) && preg_match(self::PATH, $value) === 1 ? $value : null;
    }

    /**
     * Product slug => path from the Hub sitemap (it applies the products `pathTemplate`). Empty when the sitemap cannot be read.
     *
     * @return array<string, string>
     */
    private static function productPaths(ContentClient $client): array
    {
        try {
            $entries = $client->getSitemap()['entries'] ?? [];
        } catch (Throwable) {
            return [];
        }

        $paths = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'product' && is_string($entry['slug'] ?? null) && is_string($entry['path'] ?? null)
                && preg_match(self::PATH, $entry['path']) === 1) {
                $paths[$entry['slug']] = $entry['path'];
            }
        }

        return $paths;
    }

    private static function int(mixed $value, int $min, int $max, int $default): int
    {
        return is_int($value) ? max($min, min($max, $value)) : $default;
    }

    private static function failed(string $type, Throwable $e): null
    {
        if ($e instanceof ContentException && $e->isModuleDisabled()) {
            self::log($type, 'module_disabled', 'info', "The module of the {$type} block is off: the block renders nothing.");
        } else {
            $reason = $e instanceof ContentException ? $e->errorCode() : $e::class;
            self::log($type, $reason, 'warning', "The {$type} block renders nothing: " . $e->getMessage());
        }

        return null;
    }

    private static function log(string $type, string $reason, string $level, string $message): void
    {
        $key = $type . '|' . $reason;

        if (isset(self::$logged[$key])) {
            return;
        }

        self::$logged[$key] = true;

        try {
            Log::log($level, '[fullstackstudio/content] ' . $message, ['block' => $type, 'reason' => $reason]);
        } catch (Throwable) {
            // Logging must never break a page.
        }
    }

    /** @internal tests only: forget which failures were logged. */
    public static function resetLog(): void
    {
        self::$logged = [];
    }
}
