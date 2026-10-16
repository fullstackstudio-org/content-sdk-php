<?php

declare(strict_types=1);

// Loads only src/ through a tiny autoloader: no Composer, no Laravel. Exits non-zero on the first failed assertion.

spl_autoload_register(static function (string $class): void {
    $prefix = 'FullStackStudio\\Content\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Webhooks\WebhookVerifier;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$what}\n");
        exit(1);
    }
}

check(!class_exists('Illuminate\Support\ServiceProvider', false), 'Laravel must not be loaded');

$transport = new class () implements Transport {
    public int $calls = 0;

    public function send(Request $request): Response
    {
        $this->calls++;

        return new Response(200, ['x-total-count' => '3'], '{"articles":[{"slug":"a"}],"page":1,"limit":20}');
    }
};
$client = new ContentClient(['token' => 'fsc_srv_standalone', 'transport' => $transport, 'cache' => new VersionedTagCache(new ArrayStore())]);

check($client->listArticles()['total'] === 3, 'total from header');
$client->listArticles();
check($transport->calls === 1, 'second read is cached');

$signature = WebhookVerifier::sign('s', '{"a":1}', 1760000000);
check(str_starts_with($signature, 't=1760000000,v1='), 'sign');
check(!class_exists('Illuminate\Support\ServiceProvider', false), 'Laravel still not loaded');

echo "OK\n";
