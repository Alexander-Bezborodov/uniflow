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
    $expected = $config->get('DB_INIT_TOKEN');
    if ($expected === '') {
        $expected = $config->get('WEBHOOK_SECRET');
    }
    $provided = (string) ($_GET['token'] ?? '');
    if (
        strlen($expected) < 32 ||
        strlen($provided) < 32 ||
        !hash_equals($expected, $provided)
    ) {
        http_response_code(403);
        exit();
    }
    UniFlow\Database::createDatabase($config);
    $database = new UniFlow\Database($config);
    $database->initialize();
    $imported = $database->importSqlite(
        $config->path('SQLITE_IMPORT_PATH', 'data/uniflow.db'),
    );
    echo 'UniFlow MariaDB ready. Imported tasks: ' . $imported;
} catch (Throwable $e) {
    error_log('MariaDB initialization: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'MariaDB initialization failed: ' . $e->getMessage();
}
