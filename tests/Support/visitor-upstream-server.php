<?php

declare(strict_types=1);

// Router script for `php -S` in CurlUpstreamTest: a stand-in Hub for the visitor page proxy. Never a real Hub.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/big':
        // 3 MB without Content-Length (chunked): the cap must stop the read.
        header('Content-Type: text/html');
        for ($i = 0; $i < 48; $i++) {
            echo str_repeat('x', 65536);
            flush();
        }
        break;
    case '/big-declared':
        header('Content-Type: text/html');
        header('Content-Length: ' . (3 * 1024 * 1024));
        echo str_repeat('x', 3 * 1024 * 1024);
        break;
    case '/slow':
        sleep(3);
        echo 'late';
        break;
    case '/redirect':
        header('Location: /elsewhere', true, 302);
        header('Set-Cookie: a=1');
        header('Set-Cookie: b=2', false);
        break;
    default:
        header('Content-Type: application/json');
        $files = [];
        foreach ($_FILES as $name => $file) {
            $files[$name] = ['name' => $file['name'], 'type' => $file['type'], 'body' => is_string($file['tmp_name']) && $file['tmp_name'] !== '' ? file_get_contents($file['tmp_name']) : null];
        }
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'],
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
            'proxy' => $_SERVER['HTTP_X_FSC_PROXY'] ?? null,
            'post' => $_POST,
            'files' => $files,
            'raw' => file_get_contents('php://input'),
        ]);
}
