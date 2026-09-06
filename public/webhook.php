<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $config = new UniFlow\Config(dirname(__DIR__));
    $config->validate();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit();
    }
    if (
        !hash_equals(
            $config->get('WEBHOOK_SECRET'),
            (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''),
        )
    ) {
        http_response_code(403);
        exit();
    }
    $stream = fopen('php://input', 'rb');
    $body = stream_get_contents($stream, 1048577);
    fclose($stream);
    $db = new UniFlow\Database($config->path('DATABASE_PATH', 'data/uniflow.db'));
    $status = UniFlow\Webhook::accept(
        $db,
        $config->get('WEBHOOK_SECRET'),
        'POST',
        (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'],
        $body,
    );
    http_response_code($status);
    echo $status === 200 ? 'OK' : 'Invalid request';
} catch (Throwable $e) {
    error_log('Webhook: ' . get_class($e));
    http_response_code(503);
    echo 'Unavailable';
}
