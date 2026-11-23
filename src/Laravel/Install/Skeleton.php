<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Install;

/**
 * The files of a fresh Laravel app that `content:install` removes, recognised by their SHA-256 hash: only a byte-identical file goes, so a
 * site that changed its welcome page or its routes never loses work.
 *
 * `WELCOME_VIEWS` holds every `resources/views/welcome.blade.php` Laravel 11, 12 and 13 shipped in `laravel/laravel` (tags v11.0.0 up to
 * v13.11.0, 11 variants); `public/robots.txt` and `routes/web.php` never changed in those versions. Add a hash here when a newer skeleton
 * ships a new welcome view (the install test checks the fixtures of 11, 12 and 13 against this list).
 */
final class Skeleton
{
    /** @var list<string> */
    public const WELCOME_VIEWS = [
        '1291bed6400f0b5f9bc1c4905d0cae3dba6fcfc56aa2c08f883de5ee18ec44f6',
        '2712f5909e694cf0676ace8a9b9da3e190293e7a6cb5995fde756491c8385521',
        '5fbfd8447b385e39fd91af361d15d836c8ba474d11701461c0f447ee49fdbeba',
        '61d3d38948095beeadf24a18d009892e9a9ef21a69aee9950348a8b608794c6c',
        '852105bec80506ae387b4b483ebba28e530c643942bafe94e3f6a3348ec8829d',
        '9a24d81cee3631004843d7865999fa5a8436b8a5727a056f2e626d72a9f1ba6e',
        '9e25af07b00973244ed4e21b57654f7f08a1038751de822e3409cdba00141acb',
        'bdb9bb7d2eecceb3581e429545b6ec96014ccab6d0ae83d4c52930e9a15f7d65',
        'c98bae80d596133dc1a92e70d091aff7def738dfc0f0c2c4c596d7aa6fa93395',
        'd080c375a20c256531013c5f1e5100eabcd818159ae370d8744a53ffe5c688d7',
        'e50e41602994840b788471625fec54a4db6138ec971be05cfdfba40daf906c22',
    ];

    public const ROBOTS = 'e5c4b84484ee4216e9373be99380320c25dd94805f99f0a805846f087636553f';

    public const ROUTES = '248c7eeeb43bb61ee4fb6603e52b44843c64f72a841d671bcd41871aab23f970';

    /** What `routes/web.php` becomes once the skeleton welcome route is gone. */
    public const EMPTY_ROUTES = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";

    public static function isWelcomeView(string $contents): bool
    {
        return in_array(hash('sha256', $contents), self::WELCOME_VIEWS, true);
    }

    public static function isRobots(string $contents): bool
    {
        return hash('sha256', $contents) === self::ROBOTS;
    }

    public static function isRoutes(string $contents): bool
    {
        return hash('sha256', $contents) === self::ROUTES;
    }
}
