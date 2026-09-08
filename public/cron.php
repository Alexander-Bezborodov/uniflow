<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        exit();
    }
    $config = new UniFlow\Config(dirname(__DIR__));
    $config->validate();
    $expected = $config->get('CRON_TOKEN');
    $provided = (string) ($_GET['token'] ?? '');
    if (
        strlen($expected) < 32 ||
        strlen($provided) < 32 ||
        !hash_equals($expected, $provided)
    ) {
        http_response_code(403);
        exit();
    }
    $database = new UniFlow\Database($config);
    if (!$database->initialized()) {
        throw new RuntimeException('MariaDB schema is not initialized');
    }
    $lock = fopen(dirname(__DIR__) . '/data/reminders.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        http_response_code(200);
        echo 'Already running';
        exit();
    }
    $worker = new UniFlow\Worker($database, $config);
    $worker->reminders();
    for ($i = 0; $i < 200; $i++) {
        if (!$worker->deliver()) {
            break;
        }
    }
    $worker->cleanup();
    flock($lock, LOCK_UN);
    fclose($lock);
    echo 'OK';
} catch (Throwable $e) {
    error_log('Cron: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(503);
    echo 'Unavailable';
}
