<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use UniFlow\Config;
use UniFlow\Database;
use UniFlow\Dates;

$config = new Config(dirname(__DIR__, 2), [
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_NAME' => getenv('DB_NAME') ?: 'uniflow_test',
    'DB_USER' => getenv('DB_USER') ?: 'uniflow',
    'DB_PASS' => getenv('DB_PASS') ?: 'uniflow_test',
]);
$database = new Database($config);
$database->initialize();
$database->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['outbox', 'updates', 'sessions', 'tasks', 'users', 'cache'] as $table) {
    $database->pdo->exec('TRUNCATE TABLE ' . $table);
}
$database->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$now = new DateTimeImmutable('2026-09-06T12:00:00+05:00');
$database->upsertUser([
    11,
    'tester',
    'Test User',
    'Asia/Yekaterinburg',
    Dates::utc($now),
]);
$database->create(11, [
    'title' => 'MariaDB task',
    'deadline' => Dates::utc($now->modify('+1 day')),
    'estimated_minutes' => 60,
    'importance' => 2,
], $now);
$database->saveState(11, ['step' => 'test']);
$database->setCache('test', 'value');
$update = [
    'update_id' => 100,
    'message' => [
        'message_id' => 1,
        'chat' => ['id' => 11, 'type' => 'private'],
        'from' => ['id' => 11, 'is_bot' => false],
        'text' => '/start',
    ],
];
$database->enqueue($update);
$database->enqueue($update);
$database->send(11, 'Test');
if (
    !$database->initialized() ||
    count($database->tasks(11)) !== 1 ||
    $database->state(11)['step'] !== 'test' ||
    $database->getCache('test')['value'] !== 'value' ||
    (int) $database->pdo->query('SELECT count(*) FROM updates')->fetchColumn() !== 1 ||
    (int) $database->pdo->query('SELECT count(*) FROM outbox')->fetchColumn() !== 1
) {
    throw new RuntimeException('MariaDB integration failed');
}
echo "MariaDB integration passed\n";
