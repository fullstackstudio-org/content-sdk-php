<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

/** The default site views and the publish tag of each: `php artisan vendor:publish --tag=content-site-article`. */
final class SiteViews
{
    /** @return array<string, array<string, string>> publish tag => [source => destination], for `ServiceProvider::publishes()` */
    public static function publishable(): array
    {
        $tags = [];

        foreach (Resolved::TYPES as $type) {
            $tags['content-site-' . $type] = [
                __DIR__ . '/../../../resources/views/site/' . $type . '.blade.php' => resource_path('views/vendor/content/site/' . $type . '.blade.php'),
            ];
        }

        return $tags;
    }
}
