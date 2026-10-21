<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/** Pure helpers for `<x-content::address-field>` (Dutch address autocomplete): texts, field names and input checks. No Laravel, no I/O. */
final class Address
{
    /** @var array<string, array<string, string>> */
    public const LABELS = [
        'en' => [
            'label' => 'Address',
            'search' => 'Search your address',
            'searchHint' => 'Start with your street and house number, or postcode and number.',
            'manual' => 'Enter manually',
            'change' => 'Change',
            'street' => 'Street',
            'houseNumber' => 'House number',
            'addition' => 'Addition',
            'postcode' => 'Postcode',
            'city' => 'City',
            'country' => 'Country',
            'required' => 'required',
            'noResults' => 'No addresses found. Enter it manually.',
            'results' => '{count} suggestions. Use the arrow keys to choose.',
            'selected' => 'Address selected: {address}',
        ],
        'nl' => [
            'label' => 'Adres',
            'search' => 'Zoek je adres',
            'searchHint' => 'Begin met je straat en huisnummer, of postcode en huisnummer.',
            'manual' => 'Handmatig invullen',
            'change' => 'Wijzigen',
            'street' => 'Straat',
            'houseNumber' => 'Huisnummer',
            'addition' => 'Toevoeging',
            'postcode' => 'Postcode',
            'city' => 'Plaats',
            'country' => 'Land',
            'required' => 'verplicht',
            'noResults' => 'Geen adressen gevonden. Vul het handmatig in.',
            'results' => '{count} suggesties. Gebruik de pijltjestoetsen om te kiezen.',
            'selected' => 'Adres gekozen: {address}',
        ],
    ];

    /** @var array<string, array<string, string>> */
    public const COUNTRIES = [
        'en' => ['NL' => 'Netherlands', 'BE' => 'Belgium', 'DE' => 'Germany'],
        'nl' => ['NL' => 'Nederland', 'BE' => 'België', 'DE' => 'Duitsland'],
    ];

    /** The data keys of an address and their default input suffix; `lat` and `lon` are only rendered with `geo`. */
    public const KEYS = ['street', 'houseNumber', 'addition', 'postcode', 'city', 'country', 'lat', 'lon'];

    /**
     * The label set for a locale (unknown falls back to English; `nl-NL` counts as `nl`); `$overrides` win per key.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function labels(?string $locale = null, array $overrides = []): array
    {
        return [...self::LABELS[self::lang($locale)], ...$overrides];
    }

    /** @return array<string, string> */
    public static function countries(?string $locale = null): array
    {
        return self::COUNTRIES[self::lang($locale)];
    }

    /**
     * The `name` attribute per data key: `{prefix}[street]`, ... unless `$names` maps a key to a name of your own (e.g. a flat Content form:
     * `['street' => 'straat', 'city' => 'plaats']`). Only the keys in `KEYS` are used; names with anything but letters, digits, `_`, `-`, `[` and `]` are ignored.
     *
     * @param array<string, string> $names
     * @return array<string, string>
     */
    public static function fieldNames(string $prefix, array $names = []): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $custom = $names[$key] ?? null;
            $out[$key] = is_string($custom) && preg_match('/^[A-Za-z][A-Za-z0-9_\-\[\]]{0,63}$/D', $custom) === 1 ? $custom : $prefix . '[' . $key . ']';
        }

        return $out;
    }

    /** `address[street]` becomes `address.street` (the dotted form that `old()` and `$errors` use). */
    public static function dotted(string $name): string
    {
        return rtrim(str_replace(['][', '[', ']'], ['.', '.', ''], $name), '.');
    }

    /** House letter and addition as one "addition" value: `A`, `2` or `A-2`. */
    public static function composeAddition(?string $letter, ?string $addition): string
    {
        $parts = array_values(array_filter([trim((string) $letter), trim((string) $addition)], static fn (string $part): bool => $part !== ''));

        return implode('-', $parts);
    }

    /** A Dutch postcode in canonical form (`2592VB`), or null. */
    public static function normalizePostcode(string $postcode): ?string
    {
        $value = strtoupper((string) preg_replace('/\s+/u', '', $postcode));

        return preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/D', $value) === 1 ? $value : null;
    }

    /** A free-text query with whitespace collapsed and control characters removed; null unless it is 3 to 100 characters. */
    public static function normalizeQuery(string $query): ?string
    {
        $value = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $query));
        $length = mb_strlen($value);

        return $length >= 3 && $length <= 100 ? $value : null;
    }

    private static function lang(?string $locale): string
    {
        $short = strtolower(substr((string) $locale, 0, 2));

        return isset(self::LABELS[$short]) ? $short : 'en';
    }
}
