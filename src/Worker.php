<?php

declare(strict_types=1);

namespace UniFlow;

final class Worker
{
    private Database $db;
    private Bot $bot;
    private Telegram $telegram;
    private Config $config;

    public function __construct(Database $db, Config $config, ?Telegram $telegram = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->bot = new Bot($db, $config);
        $this->telegram = $telegram ?? new Telegram($config);
    }

    public function update(): bool
    {
        $r = $this->db->one('SELECT * FROM updates WHERE processed_at IS NULL ORDER BY id LIMIT 1');
        if (!$r || (int) $r['available_at'] > time()) {
            return false;
        }
        $u = [];
        try {
            $u = json_decode($r['payload'], true, 64, JSON_THROW_ON_ERROR);
            $prepared = $this->bot->prepare($u);
            $this->db->transaction(function () use ($u, $r, $prepared): void {
                $this->bot->handle($u, $prepared);
                $this->db->run("UPDATE updates SET processed_at=?,payload='{}' WHERE id=?", [
                    time(),
                    $r['id'],
                ]);
            });
        } catch (\Throwable $e) {
            error_log('Update failed #' . $r['id'] . ': ' . get_class($e));
            $attempt = (int) $r['attempts'] + 1;
            if ($attempt >= 5) {
                $this->db->transaction(function () use ($r, $u): void {
                    $from = Bot::identity($u ?? []);
                    if ($from) {
                        $this->db->send(
                            $from['id'],
                            'Не удалось обработать действие. Попробуй /cancel и начни заново.',
                        );
                    }
                    $this->db->run(
                        "UPDATE updates SET attempts=5,processed_at=?,payload='{}' WHERE id=?",
                        [time(), $r['id']],
                    );
                });
            } else {
                $this->db->run('UPDATE updates SET attempts=?,available_at=? WHERE id=?', [
                    $attempt,
                    time() + min(60, 2 ** $attempt),
                    $r['id'],
                ]);
            }
        }
        return true;
    }

    public function reminders(): void
    {
        $now = $this->config->now();
        $utc = Dates::utc($now);
        $tasks = $this->db
            ->run(
                "SELECT t.* FROM tasks t JOIN users u ON u.telegram_id=t.user_id WHERE t.status='active' AND t.deadline>? AND t.deadline<=? AND t.reminder_3h_sent=0 AND u.reminders_disabled=0 AND u.telegram_blocked=0 AND (t.reminder_24h_sent=0 OR t.deadline<=?) ORDER BY t.deadline LIMIT 200",
                [$utc, Dates::utc($now->modify('+24 hours')), Dates::utc($now->modify('+3 hours'))],
            )
            ->fetchAll();
        $this->db->transaction(function () use ($tasks, $now): void {
            foreach ($tasks as $t) {
                $hours = strtotime($t['deadline']) - $now->getTimestamp() <= 10800 ? 3 : 24;
                $payload = [
                    'chat_id' => (int) $t['user_id'],
                    'text' => Emojis::render(
                        "⚠ <b>Дедлайн приближается</b>\n" .
                            Emojis::escape($t['title']) .
                            "\nОсталось не более $hours ч.\n📅 " .
                            Dates::label($t['deadline'], $now) .
                            ' (' .
                            Emojis::escape($now->getTimezone()->getName()) .
                            ').',
                    ),
                    'parse_mode' => 'HTML',
                    'reply_markup' => Emojis::inline([
                        [['📚 План', 'nav:today'], ['✅ Выполнено', 'task:done:' . $t['id']]],
                    ]),
                ];
                $this->db->out('sendMessage', $payload, [
                    'id' => (int) $t['id'],
                    'revision' => (int) $t['revision'],
                    'hours' => $hours,
                ]);
            }
        });
    }

    public function deliver(): bool
    {
        $pause = $this->db->one("SELECT value FROM cache WHERE key='telegram_retry_at'");
        if ($pause && (int) $pause['value'] > time()) {
            return false;
        }

        $r = $this->db->one(
            "SELECT o.* FROM outbox o WHERE sent_at IS NULL AND failed_at IS NULL AND available_at<=? AND NOT EXISTS (SELECT 1 FROM outbox p WHERE p.id<o.id AND p.sent_at IS NULL AND p.failed_at IS NULL AND p.chat_id=o.chat_id) ORDER BY CASE WHEN method='answerCallbackQuery' THEN 0 ELSE 1 END,id LIMIT 1",
            [time()],
        );
        if (!$r) {
            return false;
        }
        $p = json_decode($r['payload'], true, 64, JSON_THROW_ON_ERROR);
        if ($r['task_id'] !== null) {
            $t = $this->db->one(
                'SELECT t.*,u.reminders_disabled,u.telegram_blocked FROM tasks t JOIN users u ON u.telegram_id=t.user_id WHERE t.id=?',
                [$r['task_id']],
            );
            $hours = (int) $r['reminder_hours'];
            $seconds = $t ? strtotime($t['deadline']) - time() : 0;
            if (
                !$t ||
                $t['status'] !== 'active' ||
                (int) $t['revision'] !== (int) $r['revision'] ||
                $t['reminders_disabled'] ||
                $t['telegram_blocked'] ||
                $seconds <= 0 ||
                $t['reminder_3h_sent'] ||
                ($hours === 24 && ($seconds <= 10800 || $t['reminder_24h_sent']))
            ) {
                $this->db->run('DELETE FROM outbox WHERE id=?', [$r['id']]);
                return true;
            }
        }
        try {
            $result = $this->telegram->request($r['method'], $p);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error_code' => 503];
        }
        if (!empty($result['ok'])) {
            $this->db->transaction(function () use ($r): void {
                if ($r['task_id'] !== null) {
                    $col =
                        (int) $r['reminder_hours'] === 3 ? 'reminder_3h_sent' : 'reminder_24h_sent';
                    $this->db->run(
                        "UPDATE tasks SET $col=1 WHERE id=? AND revision=? AND status='active'",
                        [$r['task_id'], $r['revision']],
                    );
                }
                $this->discard($r);
            });
        } else {
            $code = (int) ($result['error_code'] ?? 503);
            $attempt = (int) $r['attempts'] + 1;
            if ($code === 429) {
                $delay = max(1, (int) ($result['parameters']['retry_after'] ?? 30));
                $this->db->run(
                    "INSERT INTO cache(key,value) VALUES('telegram_retry_at',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                    [(string) (time() + $delay)],
                );
                $this->db->run('UPDATE outbox SET available_at=? WHERE id=?', [
                    time() + $delay,
                    $r['id'],
                ]);
            } elseif ($code === 403 || $code === 400 || $attempt >= 8) {
                error_log('Telegram delivery stopped #' . $r['id'] . ' code=' . $code);
                if ($code === 403 && isset($p['chat_id'])) {
                    $this->db->run('UPDATE users SET telegram_blocked=1 WHERE telegram_id=?', [
                        $p['chat_id'],
                    ]);
                }
                if ($r['method'] === 'answerCallbackQuery') {
                    $this->discard($r);
                } else {
                    $this->db->run(
                        'UPDATE outbox SET failed_at=?,error_code=?,attempts=? WHERE id=?',
                        [time(), $code, $attempt, $r['id']],
                    );
                }
            } else {
                $this->db->run('UPDATE outbox SET attempts=?,available_at=? WHERE id=?', [
                    $attempt,
                    time() + min(300, 2 ** $attempt),
                    $r['id'],
                ]);
            }
        }
        return true;
    }

    private function discard(array $r): void
    {
        $this->db->run("UPDATE outbox SET sent_at=?,payload='{}' WHERE id=?", [time(), $r['id']]);
    }

    public function cleanup(): void
    {
        $this->db->run('DELETE FROM updates WHERE processed_at IS NOT NULL AND processed_at<?', [
            time() - 604800,
        ]);
        $this->db->run('DELETE FROM outbox WHERE sent_at IS NOT NULL AND sent_at<?', [
            time() - 604800,
        ]);
        $this->db->run('DELETE FROM sessions WHERE updated_at<?', [time() - 86400]);
        $this->db->run(
            "DELETE FROM cache WHERE key LIKE 'ai_rate_%' AND CAST(value AS INTEGER)<?",
            [time() - 86400],
        );
    }
}
