<?php

// SDK-owned texts of the default views (English). Every key here must exist in every other language file.
// Texts the Hub resolves per language (banner title, buttons, policy label) are NOT here: these are only the fallbacks and the
// strings the Hub has no setting for.

return [
    'consent' => [
        'title' => 'Cookies',
        'policy' => 'Privacy policy',
        'customize' => 'Customize',
        'legend' => 'Choose which cookies you allow',
        'necessary' => 'Necessary',
        'always_on' => 'always on',
        'reject_all' => 'Necessary only',
        'accept_all' => 'Accept all',
        'save' => 'Save choice',
        'categories' => [
            'analytics' => 'Statistics',
            'marketing' => 'Marketing',
            'preferences' => 'Preferences',
            'functional' => 'Functional',
        ],
    ],
];
