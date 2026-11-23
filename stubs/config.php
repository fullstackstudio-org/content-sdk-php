<?php

// Written by `php artisan content:install`. Sparse on purpose: everything not set here keeps the default of the package
// (vendor/fullstackstudio/content/config/content.php), also what a newer version adds.

return [
    'auto' => [
        // The layout the default views of the SDK extend, the section they fill, and the classes of the content container.
        'layout' => 'layouts.app',
        'section' => 'content',
        'wrap' => 'fsc-wrap',

        // Legal texts always come from the Hub's legal module: path => kind of document. Never write these texts in the site.
        'legal_paths' => [
            'privacyverklaring' => 'privacy_statement',
            'algemene-voorwaarden' => 'terms',
            'cookieverklaring' => 'cookie_statement',
        ],

        // Own handlers per page type ('page', 'article', 'articles', 'category', 'event', 'legal'): a class with `__invoke(Request $request, Resolved $resolved)`.
        'handlers' => [
            // 'page' => \App\Http\Controllers\PageController::class,
        ],
    ],
];
