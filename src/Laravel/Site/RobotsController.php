<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Robots;
use Illuminate\Http\Response;

/**
 * `GET /robots.txt` (route `content.auto.robots`): {@see Robots::automatic()}. No middleware group, so no session and no cookie.
 */
final class RobotsController
{
    public function __invoke(): Response
    {
        return Robots::automatic();
    }
}
