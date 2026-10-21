<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::address-field name="address" />`: one search field with autocomplete for a Dutch address (PDOK, through Content), a compact
 * line with "Change" after choosing, and "Enter manually" with the separate fields (street, number, addition, postcode, city, country) as the fallback.
 *
 * The fields are plain inputs in your own `<form>` (named `address[street]`, ... or the names you pass in `names`, e.g. a flat Content form:
 * `:names="['street' => 'straat', 'houseNumber' => 'huisnummer', 'addition' => 'toevoeging', 'postcode' => 'postcode', 'city' => 'plaats', 'country' => 'land']"`),
 * so they work with a normal Laravel POST and with `submitForm`. Without JavaScript the manual fields are shown. The browser asks this site's own address
 * routes (`content.address.*`, on by default), never Content directly.
 *
 * `:styles="false"` leaves out the small stylesheet (`/_fsc/address/field.css`) when you style `.fsc-address*` yourself.
 *
 * Values to show: `old()` first (after a validation error), then `:value="[...]"` (keys of `Address::KEYS`).
 */
final class AddressField extends Component
{
    /** @var array<string, string> */
    public array $labels;

    /** @var array<string, string> */
    public array $names;

    /** @var array<string, string> */
    public array $countryOptions;

    /** @var array<string, string> */
    public array $values;

    public string $suggestUrl;

    public string $lookupUrl;

    public string $scriptUrl;

    public string $stylesUrl;

    public string $id;

    public ?string $scriptNonce;

    /**
     * @param array<string, string> $names name attribute per data key (see `Address::fieldNames`)
     * @param array<string, scalar|null> $value start values per data key
     * @param array<string, string> $labels overrides for individual texts (wins over `locale`)
     * @param array<string, string>|null $countries country code => label for the select (default: NL, BE, DE)
     */
    public function __construct(
        public readonly string $name = 'address',
        public readonly bool $required = false,
        public readonly bool $geo = false,
        public readonly bool $styles = true,
        array $names = [],
        array $value = [],
        ?string $locale = null,
        array $labels = [],
        ?array $countries = null,
        ?string $id = null,
        ?string $nonce = null,
    ) {
        $locale ??= app()->getLocale();
        $this->labels = Address::labels($locale, $labels);
        $this->countryOptions = $countries ?? Address::countries($locale);
        $this->names = Address::fieldNames($name, $names);
        $this->id = $id !== null && preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/D', $id) === 1 ? $id : 'fsc-address-' . substr(md5($name . implode('|', $this->names)), 0, 8);
        $this->values = self::resolveValues($this->names, $value);

        if ($this->values['country'] === '') {
            $this->values['country'] = array_key_first($this->countryOptions) ?: 'NL';
        }

        if (!isset($this->countryOptions[$this->values['country']])) {
            $this->countryOptions[$this->values['country']] = $this->values['country'];
        }

        $base = '/' . trim((string) config('content.address.path', '_fsc/address'), '/');
        $this->suggestUrl = Route::has('content.address.suggest') ? route('content.address.suggest', [], false) : $base . '/suggest';
        $this->lookupUrl = Route::has('content.address.lookup') ? route('content.address.lookup', [], false) : $base . '/lookup';
        $this->scriptUrl = Route::has('content.address.script') ? route('content.address.script', [], false) : $base . '/field.js';
        $this->stylesUrl = Route::has('content.address.styles') ? route('content.address.styles', [], false) : $base . '/field.css';
        $this->scriptNonce = $nonce ?? self::viteNonce();
    }

    public function render(): View
    {
        return view('content::components.address-field');
    }

    /**
     * The JSON the script reads (texts and the country that the autocomplete sets).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'suggestUrl' => $this->suggestUrl,
            'lookupUrl' => $this->lookupUrl,
            'labels' => $this->labels,
            'required' => $this->required,
        ];
    }

    /**
     * `old()` (after a failed validation) wins over the `value` attribute.
     *
     * @param array<string, string> $names
     * @param array<string, scalar|null> $value
     * @return array<string, string>
     */
    private static function resolveValues(array $names, array $value): array
    {
        $values = [];

        foreach (Address::KEYS as $key) {
            $old = null;

            try {
                $old = old(Address::dotted($names[$key]));
            } catch (Throwable) {
                // No session (stateless route): nothing to restore.
            }

            $candidate = $old ?? ($value[$key] ?? '');
            $values[$key] = is_scalar($candidate) ? mb_substr((string) $candidate, 0, 200) : '';
        }

        return $values;
    }

    private static function viteNonce(): ?string
    {
        try {
            $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;
        } catch (Throwable) {
            $nonce = null;
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
