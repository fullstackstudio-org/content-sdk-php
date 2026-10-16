<?php

// Router script for `php -S` in FormFilesTest: answers every request with what arrived (content type + raw body, base64).
header('Content-Type: application/json');
echo json_encode(['contentType' => $_SERVER['CONTENT_TYPE'] ?? null, 'method' => $_SERVER['REQUEST_METHOD'], 'body' => base64_encode(file_get_contents('php://input'))]);
