<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/** Pure helpers for the server-side form (`<x-content::form>`), a port of `form-utils.ts`. No Laravel, no I/O. */
final class Forms
{
    /** @var array<string, array<string, string>> */
    public const LABELS = [
        'en' => [
            'submit' => 'Send',
            'required' => 'required',
            'fieldRequired' => 'This field is required.',
            'tooLong' => 'Maximum {max} characters.',
            'selectPlaceholder' => 'Choose…',
            'genericError' => 'Sending failed. Please try again later.',
            'rateLimited' => 'You have submitted too often. Please try again in a minute.',
            'turnstileRequired' => 'Please confirm that you are not a robot first.',
            'loadError' => 'The form could not be loaded.',
            'honeypot' => 'Leave this field empty',
            'invalidAmount' => 'Enter a valid amount.',
            'fileTooLarge' => 'A file is too large.',
            'fileType' => 'One of the files is not an allowed type.',
        ],
        'nl' => [
            'submit' => 'Versturen',
            'required' => 'verplicht',
            'fieldRequired' => 'Dit veld is verplicht.',
            'tooLong' => 'Maximaal {max} tekens.',
            'selectPlaceholder' => 'Kies…',
            'genericError' => 'Het versturen is niet gelukt. Probeer het later opnieuw.',
            'rateLimited' => 'Je hebt te vaak verstuurd. Probeer het over een minuut opnieuw.',
            'turnstileRequired' => 'Bevestig eerst dat je geen robot bent.',
            'loadError' => 'Het formulier kon niet worden geladen.',
            'honeypot' => 'Laat dit veld leeg',
            'invalidAmount' => 'Vul een geldig bedrag in.',
            'fileTooLarge' => 'Een bestand is te groot.',
            'fileType' => 'Een van de bestanden heeft geen toegestaan type.',
        ],
    ];

    /** Extensions per `accept` token of a `file` field (the API's fixed set), for the HTML `accept` attribute. */
    public const FILE_EXTENSIONS = [
        'pdf' => ['.pdf'], 'jpg' => ['.jpg', '.jpeg'], 'png' => ['.png'], 'webp' => ['.webp'], 'heic' => ['.heic', '.heif'], 'gif' => ['.gif'],
        'doc' => ['.doc'], 'docx' => ['.docx'], 'xls' => ['.xls'], 'xlsx' => ['.xlsx'], 'odt' => ['.odt'], 'ods' => ['.ods'], 'txt' => ['.txt'], 'csv' => ['.csv'], 'zip' => ['.zip'],
    ];

    private const TEXT_LENGTH_TYPES = ['text', 'textarea', 'email', 'tel', 'url'];
    private const ISSUE_PREFIXES = ['Ongeldige inzending:', 'Invalid submission:'];

    /**
     * The label set for a locale (unknown falls back to English; `nl-NL` counts as `nl`); `$overrides` win per key.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    /**
     * The first-touch attribution that the analytics helper puts in the hidden field `_fsc_attribution` (or `fscAnalytics.attribution()`
     * in your own form), as `_meta` keys for `submitForm`: `utm_*`, the ad click ids (`gclid`, `fbclid`, `msclkid`, ...), `landing_url`
     * and `referrer`. Unknown keys, non-text values and control characters are dropped; values are cut to 500 characters.
     *
     * @return array<string, string>
     */
    public static function attributionMeta(mixed $json): array
    {
        $data = is_string($json) && $json !== '' && strlen($json) <= 16_384 ? json_decode($json, true) : (is_array($json) ? $json : null);

        if (!is_array($data)) {
            return [];
        }

        $meta = [];

        foreach ([...Analytics::DEFAULT_PARAMS, 'landing_url', 'referrer'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value)) {
                $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

                if ($value !== '') {
                    $meta[$key] = mb_substr($value, 0, 500);
                }
            }
        }

        return $meta;
    }

    public static function labels(?string $locale = null, array $overrides = []): array
    {
        $short = strtolower(substr((string) $locale, 0, 2));

        return [...self::LABELS[isset(self::LABELS[$short]) ? $short : 'en'], ...$overrides];
    }

    /**
     * The values of the request that belong to the form: its fields as entered (a checkbox as bool, a `choice` payment as cents)
     * plus the honeypot field. Anything else in the request is ignored.
     * File fields come from `$files` (`name => SplFileInfo|list<SplFileInfo>`, e.g. `$request->allFiles()`) and are only taken when the definition has
     * a `file` field of that name; empty or invalid uploads are dropped. A form without file fields gets exactly the old result.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, string|bool|int|\SplFileInfo|list<\SplFileInfo>>
     */
    public static function values(array $definition, array $input, array $files = []): array
    {
        $values = [];

        foreach ($definition['fields'] ?? [] as $field) {
            $name = (string) $field['name'];
            $type = (string) ($field['type'] ?? 'text');
            $raw = $input[$name] ?? null;

            if ($type === 'file') {
                $list = self::uploads($files[$name] ?? null);

                if ($list !== []) {
                    $values[$name] = $list;
                }
            } elseif ($type === 'checkbox') {
                $values[$name] = $raw !== null && $raw !== '' && $raw !== '0' && $raw !== false;
            } elseif ($type === 'payment') {
                // `fixed`: the server decides the amount, send nothing. `choice`: the visitor enters major units, Content wants cents.
                if (($field['payment']['mode'] ?? '') === 'choice' && is_scalar($raw) && trim((string) $raw) !== '') {
                    $cents = self::cents((string) $raw);
                    $values[$name] = $cents ?? (string) $raw;
                }
            } else {
                $values[$name] = is_scalar($raw) ? (string) $raw : '';
            }
        }

        $honeypot = (string) ($definition['honeypotField'] ?? '');

        if ($honeypot !== '') {
            $value = $input[$honeypot] ?? '';
            $values[$honeypot] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * Uploads of `file` fields that failed on the PHP side (size limits, partial upload, no tmp dir, ...): the `UPLOAD_ERR_*` codes, without
     * `UPLOAD_ERR_NO_FILE` (an empty file input is not a problem). `values()` drops such uploads, so a caller must check this and refuse the submit.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $files e.g. `$request->allFiles()`
     * @return list<int>
     */
    public static function uploadErrors(array $definition, array $files): array
    {
        $codes = [];

        foreach ($definition['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }

            $entry = $files[(string) $field['name']] ?? null;

            foreach (is_array($entry) ? $entry : [$entry] as $file) {
                if (is_object($file) && method_exists($file, 'getError')) {
                    $code = (int) $file->getError();

                    if ($code !== UPLOAD_ERR_OK && $code !== UPLOAD_ERR_NO_FILE) {
                        $codes[] = $code;
                    }
                }
            }
        }

        return $codes;
    }

    /**
     * The usable uploads out of one `allFiles()` entry (a file or a list of files): real files with content.
     *
     * @return list<\SplFileInfo>
     */
    private static function uploads(mixed $entry): array
    {
        $list = [];

        foreach (is_array($entry) ? $entry : [$entry] as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getSize() === 0) {
                continue;
            }

            if (method_exists($file, 'isValid') && !$file->isValid()) {
                continue;
            }

            $list[] = $file;
        }

        return $list;
    }

    /** The value of the HTML `accept` attribute for a `file` field, e.g. `.pdf,.jpg,.jpeg` (empty when the field has no `file.accept`). @param array<string, mixed> $field */
    public static function acceptAttribute(array $field): string
    {
        $extensions = [];

        foreach ((array) ($field['file']['accept'] ?? []) as $token) {
            foreach (self::FILE_EXTENSIONS[(string) $token] ?? [] as $extension) {
                $extensions[] = $extension;
            }
        }

        return implode(',', $extensions);
    }

    /** The form has a `file` field: its `<form>` needs `enctype="multipart/form-data"`. @param array<string, mixed> $definition */
    public static function hasFileField(array $definition): bool
    {
        foreach ($definition['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'file') {
                return true;
            }
        }

        return false;
    }

    /** "12", "12,50" or "12.5" => cents; null if it is not an amount. */
    public static function cents(string $amount): ?int
    {
        $amount = str_replace(',', '.', trim($amount));

        return preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1 ? (int) round((float) $amount * 100) : null;
    }

    /**
     * Required fields and maximum length (the API checks the rest). Returns field name => message.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $values the result of {@see values()}
     * @param array<string, string> $labels
     * @return array<string, string>
     */
    public static function validate(array $definition, array $values, array $labels): array
    {
        $errors = [];

        foreach ($definition['fields'] ?? [] as $field) {
            $name = (string) $field['name'];
            $type = (string) ($field['type'] ?? 'text');
            $required = (bool) ($field['required'] ?? false);

            if ($type === 'payment') {
                if (($field['payment']['mode'] ?? '') === 'choice' && ($required || isset($values[$name]))) {
                    if (!isset($values[$name])) {
                        $errors[$name] = $labels['fieldRequired'];
                    } elseif (!is_int($values[$name])) {
                        $errors[$name] = $labels['invalidAmount'];
                    }
                }

                continue;
            }

            $value = $values[$name] ?? null;

            if ($type === 'file') {
                if ($required && !is_array($value)) {
                    $errors[$name] = $labels['fieldRequired'];
                }

                continue;
            }

            if ($required && ($type === 'checkbox' ? $value !== true : !is_string($value) || trim($value) === '')) {
                $errors[$name] = $labels['fieldRequired'];
            } elseif (in_array($type, self::TEXT_LENGTH_TYPES, true) && is_string($value) && mb_strlen($value) > (int) ($field['maxLength'] ?? PHP_INT_MAX)) {
                $errors[$name] = str_replace('{max}', (string) $field['maxLength'], $labels['tooLong']);
            }
        }

        return $errors;
    }

    /**
     * For an invalid submission the API returns one sentence, e.g. `Ongeldige inzending: email is geen geldig e-mailadres; naam is verplicht (en 2 meer)`.
     * This extracts the message per field (only fields in the definition). Best-effort parsing of human-readable text, not a contract: whatever
     * cannot be recognized ends up in `rest`.
     *
     * @param list<array<string, mixed>> $fields
     * @return array{fieldErrors: array<string, string>, rest: list<string>}
     */
    public static function parseFieldErrors(string $message, array $fields): array
    {
        $fieldErrors = [];
        $rest = [];
        $prefix = null;

        foreach (self::ISSUE_PREFIXES as $candidate) {
            if (str_starts_with($message, $candidate)) {
                $prefix = $candidate;
                break;
            }
        }

        if ($prefix === null) {
            return ['fieldErrors' => [], 'rest' => [$message]];
        }

        $body = preg_replace('/\s*\((?:en|and) \d+ (?:meer|more)\)\s*$/', '', substr($message, strlen($prefix))) ?? '';

        foreach (explode(';', $body) as $part) {
            $issue = trim($part);

            if ($issue === '') {
                continue;
            }

            $matched = null;

            foreach ($fields as $field) {
                if (str_starts_with($issue, $field['name'] . ' ')) {
                    $matched = (string) $field['name'];
                    break;
                }
            }

            if ($matched !== null && !isset($fieldErrors[$matched])) {
                $fieldErrors[$matched] = ucfirst(trim(substr($issue, strlen($matched) + 1)));
            } else {
                $rest[] = $issue;
            }
        }

        return ['fieldErrors' => $fieldErrors, 'rest' => $rest];
    }
}
