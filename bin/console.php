<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
try {
    $config = new UniFlow\Config(dirname(__DIR__));
    $config->validate();
    $command = $argv[1] ?? 'help';
    $path = $config->path('DATABASE_PATH', 'data/uniflow.db');
    $db = new UniFlow\Database($path);
    if ($command === 'migrate') {
        if ((int)$db->pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn() > 0) {
            $backup = $path . '.backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $db->pdo->exec('VACUUM INTO ' . $db->pdo->quote($backup));
            echo "Backup: $backup\n";
        }
        $db->initialize();
        echo "Database ready\n";
    } elseif ($command === 'webhook:set') {
        $url = $config->get('WEBHOOK_URL');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Set HTTPS WEBHOOK_URL');
        }
        $result = (new UniFlow\Telegram($config))->request('setWebhook', ['url' => $url,'secret_token' => $config->get('WEBHOOK_SECRET'),'allowed_updates' => ['message','callback_query'],'max_connections' => 1,'drop_pending_updates' => false]);
        if (empty($result['ok'])) {
            throw new RuntimeException('setWebhook failed');
        } echo "Webhook registered\n";
    } elseif ($command === 'webhook:info') {
        $r = (new UniFlow\Telegram($config))->request('getWebhookInfo', []);
        if (empty($r['ok'])) {
            throw new RuntimeException('getWebhookInfo failed');
        }
        echo json_encode($r['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($command === 'worker') {
        $lock = fopen($path . '.worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            exit(0);
        }
        $worker = new UniFlow\Worker($db, $config);
        $once = in_array('--once', $argv, true);
        $lastReminder = 0;
        $lastCleanup = 0;
        $start = time();
        do {
            $busy = false;

            for ($i = 0; $i < 10; $i++) {
                if (!$worker->update()) {
                    break;
                } $busy = true;
            }
            if (time() - $lastReminder >= 30) {
                $worker->reminders();
                $lastReminder = time();
            }
            for ($i = 0; $i < 30; $i++) {
                if (!$worker->deliver()) {
                    break;
                } $busy = true;
            }
            if (time() - $lastCleanup >= 3600) {
                $worker->cleanup();
                $lastCleanup = time();
            }
            if ($once && (!$busy || time() - $start >= 50)) {
                break;
            }
            if (!$busy) {
                usleep(250000);
            }
        } while (true);
        flock($lock, LOCK_UN);
        fclose($lock);
    } elseif ($command === 'outbox:retry') {
        $id = $argv[2] ?? '';
        if (!ctype_digit($id)) {
            throw new RuntimeException('Specify failed outbox ID');
        }
        $n = $db->run('UPDATE outbox SET failed_at=NULL,error_code=NULL,attempts=0,available_at=0 WHERE id=? AND failed_at IS NOT NULL AND sent_at IS NULL', [(int)$id])->rowCount();
        echo $n . " message queued for retry\n";
    } elseif ($command === 'status') {
        foreach (['updates' => 'processed_at','outbox' => 'sent_at'] as $table => $column) {
            echo $table . ': ' . $db->pdo->query("SELECT count(*) FROM $table WHERE $column IS NULL" . ($table === 'outbox' ? ' AND failed_at IS NULL' : ''))->fetchColumn() . " pending\n";
        }
        foreach ($db->run('SELECT id,error_code FROM outbox WHERE failed_at IS NOT NULL ORDER BY id LIMIT 20')->fetchAll() as $failed) {
            echo 'Failed outbox #' . $failed['id'] . ' code=' . $failed['error_code'] . "\n";
        }
    } else {
        echo "Commands: migrate, webhook:set, webhook:info, worker [--once], status, outbox:retry ID\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
