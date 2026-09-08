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
    $db = new UniFlow\Database($config);
    if ($command === 'migrate') {
        $db->initialize();
        echo "Database ready\n";
    } elseif ($command === 'webhook:set') {
        $url = $config->get('WEBHOOK_URL');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Set HTTPS WEBHOOK_URL');
        }
        $telegram = new UniFlow\Telegram($config);
        $relayWebhookUrl = $telegram->createIncomingWebhookUrl($url);
        $result = $telegram->request('setWebhook', ['url' => $relayWebhookUrl,'secret_token' => $config->get('WEBHOOK_SECRET'),'allowed_updates' => ['message','callback_query'],'max_connections' => 1,'drop_pending_updates' => false]);
        if (empty($result['ok'])) {
            throw new RuntimeException('setWebhook failed');
        } echo "Webhook registered\n";
    } elseif ($command === 'webhook:info') {
        $r = (new UniFlow\Telegram($config))->request('getWebhookInfo', []);
        if (empty($r['ok'])) {
            throw new RuntimeException('getWebhookInfo failed');
        }
        $info = $r['result'];
        if (!empty($info['url'])) {
            $parts = parse_url($info['url']);
            $info['url'] = is_array($parts) && isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . '/webhook/***'
                : '***';
        }
        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($command === 'reminders') {
        $lock = fopen(dirname(__DIR__) . '/data/reminders.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            exit(0);
        }
        $worker = new UniFlow\Worker($db, $config);
        $worker->reminders();
        for ($i = 0; $i < 200; $i++) {
            if (!$worker->deliver()) {
                break;
            }
        }
        $worker->cleanup();
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
        echo "Commands: migrate, webhook:set, webhook:info, reminders, status, outbox:retry ID\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
