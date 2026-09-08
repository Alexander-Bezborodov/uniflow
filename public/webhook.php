<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $config = new UniFlow\Config(dirname(__DIR__));
    $config->validate();
    $db = new UniFlow\Database($config);
    if (!$db->initialized()) {
        throw new RuntimeException('MariaDB schema is not initialized');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        http_response_code(200);
        exit();
    }
    if ($method !== 'POST') {
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
    $status = UniFlow\Webhook::accept(
        $db,
        $config->get('WEBHOOK_SECRET'),
        'POST',
        (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'],
        $body,
    );
    http_response_code($status);
    if ($status !== 200) {
        exit();
    }

    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        header('Content-Length: 0');
        header('Connection: close');
        flush();
    }

    $worker = new UniFlow\Worker($db, $config);
    for ($i = 0; $i < 10; $i++) {
        if (!$worker->update()) {
            break;
        }
    }
    for ($i = 0; $i < 30; $i++) {
        if (!$worker->deliver()) {
            break;
        }
    }
} catch (Throwable $e) {
    error_log('Webhook: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(503);
}
