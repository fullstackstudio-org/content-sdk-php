<?php

declare(strict_types=1);

/**
 * Builds `resources/assets/blocks.css` from `resources/assets/content.css`: the consent banner is left out and every selector is wrapped in
 * `:where()`, so the inline block styles have zero specificity and the site's own CSS always wins. `tests/Laravel/BlockStyleTest.php` checks the
 * committed file against this output.
 *
 *   php scripts/build-block-css.php
 */

/** @return string the generated stylesheet */
function build_block_css(string $source): string
{
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $root = [];
    $rules = rules($source, $root);

    // One `:root` rule holds every variable, first, so each is declared exactly once.
    return ':where(:root){' . implode(' ', $root) . '}' . "\n" . implode("\n", $rules) . "\n";
}

/**
 * Builds `resources/assets/site.css` from `resources/assets/site-source.css`: the default views of the out of the box site, every selector in
 * `:where()`. It declares no variables: `blocks.css` (printed in front of it) holds the theme layer.
 *
 * @return string the generated stylesheet
 */
function build_site_css(string $source): string
{
    $root = [];
    $rules = rules((string) preg_replace('#/\*.*?\*/#s', '', $source), $root);

    return implode("\n", $rules) . "\n";
}

/** Literal colours of the base rules that become theme variables (the defaults live in the `:root` rule of content.css). */
const LITERALS = [
    '#fff' => 'var(--fsc-hero-text)',
    'rgb(0 0 0 / 0.6)' => 'var(--fsc-hero-shadow-color)',
];

/**
 * Splits a selector list on its top level commas only: `:is(h2, h3)` and `:not(a, b)` keep theirs.
 *
 * @return list<string>
 */
function split_selectors(string $prelude): array
{
    $parts = [];
    $depth = 0;
    $current = '';

    foreach (str_split($prelude) as $char) {
        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;
        }

        if ($char === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';

            continue;
        }

        $current .= $char;
    }

    $parts[] = $current;

    return $parts;
}

/**
 * @param list<string> $root receives the declarations of every `:root` rule
 *
 * @return list<string>
 */
function rules(string $css, array &$root): array
{
    $out = [];
    $length = strlen($css);
    $i = 0;

    while ($i < $length) {
        $open = strpos($css, '{', $i);

        if ($open === false) {
            break;
        }

        $prelude = trim(substr($css, $i, $open - $i));
        $depth = 1;
        $j = $open + 1;

        while ($j < $length && $depth > 0) {
            $depth += $css[$j] === '{' ? 1 : ($css[$j] === '}' ? -1 : 0);
            $j++;
        }

        $body = substr($css, $open + 1, $j - $open - 2);
        $i = $j;

        if (str_starts_with($prelude, '@media')) {
            $inner = rules($body, $root);

            if ($inner !== []) {
                $out[] = $prelude . '{' . implode('', $inner) . '}';
            }

            continue;
        }

        if ($prelude === ':root') {
            foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
                $root[] = $declaration . ';';
            }

            continue;
        }

        $selectors = array_values(array_filter(array_map('trim', split_selectors($prelude)), static fn (string $s): bool => $s !== '' && !str_starts_with($s, '.fsc-consent')));

        if ($selectors === []) {
            continue;
        }

        $declarations = strtr(trim((string) preg_replace('/\s+/', ' ', $body)), LITERALS);
        $out[] = implode(',', array_map(static fn (string $s): string => ':where(' . $s . ')', $selectors)) . '{' . $declarations . '}';
    }

    return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $dir = dirname(__DIR__) . '/resources/assets';
    file_put_contents($dir . '/blocks.css', build_block_css((string) file_get_contents($dir . '/content.css')));
    echo "resources/assets/blocks.css written\n";
    file_put_contents($dir . '/site.css', build_site_css((string) file_get_contents($dir . '/site-source.css')));
    echo "resources/assets/site.css written\n";
}
