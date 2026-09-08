<?php

declare(strict_types=1);

$key = $_GET['key'] ?? '';
if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
    http_response_code(404);
    exit;
}
$file = dirname(__DIR__) . '/data/calendars/' . $key . '.jpg';
if (!is_file($file)) {
    $file = dirname(__DIR__) . '/data/calendars/' . $key . '.png';
}
if (!is_file($file) || filemtime($file) < time() - 172800) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (substr($file, -4) === '.jpg' ? 'image/jpeg' : 'image/png'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file);
