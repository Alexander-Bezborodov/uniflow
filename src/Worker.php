<?php

declare(strict_types=1);

namespace UniFlow;

final class Worker
{
    private Database $db;
    private Bot $bot;
    private Telegram $telegram;
    private Config $config;

    public function __construct(
        Database $db,
        Config $config,
        ?Telegram $telegram = null,
        ?Bot $bot = null
    )
    {
        $this->db = $db;
        $this->config = $config;
        $this->bot = $bot ?? new Bot($db, $config);
        $this->telegram = $telegram ?? new Telegram($config);
    }

    public function update(): bool
    {
        $r = $this->db->one('SELECT * FROM updates WHERE processed_at IS NULL ORDER BY id LIMIT 1');
        if (!$r || (int) $r['available_at'] > time()) {
            return false;
        }
        $u = [];
        $thinkingMessageId = null;
        try {
            $u = json_decode($r['payload'], true, 64, JSON_THROW_ON_ERROR);
            $thinking = $this->bot->thinking($u);
            if ($thinking !== null) {
                try {
                    $result = $this->telegram->request($thinking['method'], $thinking['payload']);
                    if (!empty($result['ok'])) {
                        $thinkingMessageId = $thinking['method'] === 'sendMessage'
                            ? ($result['result']['message_id'] ?? null)
                            : ($thinking['payload']['message_id'] ?? null);
                    }
                } catch (\Throwable $e) {
                }
            }
            $prepared = $this->bot->prepare($u);
            if (is_int($thinkingMessageId)) {
                $prepared['_thinking_message_id'] = $thinkingMessageId;
                $prepared['_thinking_has_keyboard'] = isset($thinking['payload']['reply_markup']['keyboard']);
            }
            $this->db->transaction(function () use ($u, $r, $prepared): void {
                $this->bot->handle($u, $prepared);
                $this->db->run("UPDATE updates SET processed_at=?,payload='{}' WHERE id=?", [
                    time(),
                    $r['id'],
                ]);
            });
        } catch (\Throwable $e) {
            error_log('Update failed #' . $r['id'] . ': ' . get_class($e));
            $from = Bot::identity($u ?? []);
            if ($from && is_int($thinkingMessageId)) {
                try {
                    $errorPayload = [
                        'chat_id' => $from['id'],
                        'text' => 'Не удалось получить ответ GigaChat. Попробуйте отправить задачу ещё раз.',
                    ];
                    $method = 'sendMessage';
                    if (!isset($thinking['payload']['reply_markup']['keyboard'])) {
                        $method = 'editMessageText';
                        $errorPayload['message_id'] = $thinkingMessageId;
                    }
                    $this->telegram->request($method, $errorPayload);
                } catch (\Throwable $ignored) {
                }
            }
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
        $overdue = $this->db
            ->run(
                "SELECT t.*,u.language FROM tasks t JOIN users u ON u.telegram_id=t.user_id WHERE t.status='active' AND t.deadline<=? AND t.overdue_sent=0 AND u.reminders_disabled=0 AND u.telegram_blocked=0 ORDER BY t.deadline LIMIT 200",
                [$utc],
            )
            ->fetchAll();
        $tasks = $this->db
            ->run(
                "SELECT t.*,u.language FROM tasks t JOIN users u ON u.telegram_id=t.user_id WHERE t.status='active' AND t.deadline>? AND t.deadline<=? AND u.reminders_disabled=0 AND u.telegram_blocked=0 AND (t.reminder_24h_sent=0 OR t.reminder_3h_sent=0) ORDER BY t.deadline LIMIT 200",
                [$utc, Dates::utc($now->modify('+12 hours'))],
            )
            ->fetchAll();
        $this->db->transaction(function () use ($overdue, $tasks, $now): void {
            foreach ($overdue as $t) {
                $english = ($t['language'] ?? 'ru') === 'en';
                $payload = [
                    'chat_id' => (int) $t['user_id'],
                    'text' => Emojis::render(
                        ($english ? '⚠ <b>You missed a task deadline</b>' : '⚠ <b>Вы просрочили задачу</b>') .
                            "\n" .
                            Emojis::escape($t['title']) .
                            "\n📅 " .
                            Dates::label($t['deadline'], $now),
                    ),
                    'parse_mode' => 'HTML',
                    'reply_markup' => Emojis::inline([[
                        [$english ? 'Reschedule' : 'Перенести', 'task:reschedule:' . $t['id']],
                        [$english ? '✅ Complete' : '✅ Выполнено', 'task:done:' . $t['id']],
                    ]]),
                ];
                $this->db->out('sendMessage', $payload, [
                    'id' => (int) $t['id'],
                    'revision' => (int) $t['revision'],
                    'hours' => 0,
                ]);
            }
            foreach ($tasks as $t) {
                $seconds = strtotime($t['deadline']) - $now->getTimestamp();
                $hours = null;
                if ($seconds <= 7200 && !$t['reminder_3h_sent']) {
                    $hours = 2;
                } elseif (
                    $seconds > 7200 &&
                    !empty($t['importance_visible']) &&
                    (int) $t['importance'] === 3 &&
                    $seconds <= 43200 &&
                    !$t['reminder_24h_sent']
                ) {
                    $hours = 12;
                } elseif (
                    $seconds > 7200 &&
                    !empty($t['importance_visible']) &&
                    (int) $t['importance'] === 2 &&
                    $seconds <= 21600 &&
                    !$t['reminder_24h_sent']
                ) {
                    $hours = 6;
                }
                if ($hours === null) {
                    continue;
                }
                $english = ($t['language'] ?? 'ru') === 'en';
                $remaining = $seconds <= 1800
                    ? self::remainingMinutes((int) ceil($seconds / 60), $english)
                    : ($english ? "No more than $hours hours left." : "Осталось не более $hours ч.");
                $payload = [
                    'chat_id' => (int) $t['user_id'],
                    'text' => Emojis::render(
                        ($english ? '⚠ <b>Deadline approaching</b>' : '⚠ <b>Дедлайн приближается</b>') . "\n" .
                            Emojis::escape($t['title']) .
                            "\n$remaining\n📅 " .
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
        $pause = $this->db->getCache('telegram_retry_at');
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
                ($hours === 0 && ($seconds > 0 || $t['overdue_sent'])) ||
                ($hours !== 0 && $seconds <= 0) ||
                ($hours === 2 && $t['reminder_3h_sent']) ||
                ($hours !== 0 && $hours !== 2 && ($seconds <= 7200 || $t['reminder_24h_sent']))
            ) {
                $this->db->run('DELETE FROM outbox WHERE id=?', [$r['id']]);
                return true;
            }
        }
        if ($r['method'] === 'sendTaskCalendar') {
            try {
                $profile = $this->db->one('SELECT language FROM users WHERE telegram_id=?', [(int) $p['chat_id']]);
                $loading = [
                    'chat_id' => $p['chat_id'],
                    'text' => Emojis::render(($profile['language'] ?? 'ru') === 'en'
                        ? '⏳ Showing the calendar...'
                        : '⏳ Показываем календарь...'),
                    'parse_mode' => 'HTML',
                ];
                if (isset($p['message_id'])) {
                    $loading['message_id'] = $p['message_id'];
                    $loading['reply_markup'] = ['inline_keyboard' => []];
                }
                $progress = $this->telegram->request(isset($p['message_id']) ? 'editMessageText' : 'sendMessage', $loading);
                if (!empty($progress['ok']) && !isset($p['message_id']) && isset($progress['result']['message_id'])) {
                    $p['message_id'] = (int) $progress['result']['message_id'];
                    $this->db->run('UPDATE outbox SET payload=? WHERE id=?', [
                        json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $r['id'],
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Calendar loading: ' . get_class($e));
            }
            try {
                $photo = TaskCalendar::prepare($this->config, $this->db, $this->telegram, (int) $p['chat_id'], $p['month']);
                $p['rich_message'] = ['html' => '<img src="' . htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') . '"><p>' . nl2br($p['text'] ?? '', false) . '</p>'];
                unset($p['month'], $p['text']);
                $method = isset($p['message_id']) ? 'editMessageText' : 'sendRichMessage';
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
                $codes = [
                    'calendar_render_failed' => 'CALENDAR_RENDER',
                    'calendar_relay_failed' => 'CALENDAR_RELAY',
                    'Calendar requires PHP GD with FreeType' => 'CALENDAR_GD',
                    'Calendar cache unavailable' => 'CALENDAR_CACHE',
                    'Calendar cache write failed' => 'CALENDAR_CACHE',
                ];
                $code = $codes[$reason] ?? 'CALENDAR_ERROR';
                error_log('Calendar: ' . $code . ' ' . get_class($e->getPrevious() ?? $e));
                $p['text'] = ($p['text'] ?? '') . "\n\n" .
                    (($profile['language'] ?? 'ru') === 'en'
                        ? 'Calendar image unavailable. Code: '
                        : 'Не удалось загрузить календарь. Код: ') . $code;
                unset($p['month']);
                $p['parse_mode'] = 'HTML';
                $method = isset($p['message_id']) ? 'editMessageText' : 'sendMessage';
            }
            $this->db->run('UPDATE outbox SET method=?,payload=? WHERE id=?', [
                $method, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $r['id'],
            ]);
            $r['method'] = $method;
        }

        try {
            $result = $this->telegram->request($r['method'], $p);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error_code' => 503];
        }
        if (!empty($result['ok'])) {
            $this->db->transaction(function () use ($r): void {
                if ($r['task_id'] !== null) {
                    $hours = (int) $r['reminder_hours'];
                    $col = $hours === 0
                        ? 'overdue_sent'
                        : ($hours === 2 ? 'reminder_3h_sent' : 'reminder_24h_sent');
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
                $this->db->setCache('telegram_retry_at', (string) (time() + $delay));
                $this->db->run('UPDATE outbox SET available_at=? WHERE id=?', [
                    time() + $delay,
                    $r['id'],
                ]);
            } elseif ($code === 400 && $r['method'] === 'editMessageText' &&
                stripos($result['description'] ?? '', 'message is not modified') !== false) {
                $this->discard($r);
            } elseif ($code === 400 && $r['method'] === 'editMessageText' &&
                preg_match("~message (?:can.t be edited|to edit not found)~i", $result['description'] ?? '')) {
                unset($p['message_id']);
                $fallbackMethod = isset($p['rich_message']) ? 'sendRichMessage' : 'sendMessage';
                $this->db->run('UPDATE outbox SET method=?,payload=?,attempts=0,available_at=0 WHERE id=?', [
                    $fallbackMethod, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $r['id'],
                ]);
            } elseif (
                $code === 400 &&
                $r['method'] === 'sendPhoto' &&
                isset($p['chat_id'], $p['caption'])
            ) {
                $fallback = [
                    'chat_id' => $p['chat_id'],
                    'text' => $p['caption'],
                    'parse_mode' => $p['parse_mode'] ?? 'HTML',
                ];
                if (isset($p['reply_markup'])) {
                    $fallback['reply_markup'] = $p['reply_markup'];
                }
                $this->db->run(
                    'UPDATE outbox SET method=?,payload=?,attempts=0,available_at=0 WHERE id=?',
                    [
                        'sendMessage',
                        json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        $r['id'],
                    ],
                );
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

    private static function remainingMinutes(int $minutes, bool $english): string
    {
        $minutes = max(1, $minutes);
        if ($english) {
            return $minutes === 1 ? '1 minute left.' : "$minutes minutes left.";
        }
        $lastTwo = $minutes % 100;
        $last = $minutes % 10;
        $word = $lastTwo >= 11 && $lastTwo <= 14
            ? 'минут'
            : ($last === 1 ? 'минута' : ($last >= 2 && $last <= 4 ? 'минуты' : 'минут'));
        return "Осталось $minutes $word.";
    }

    public function cleanup(): void
    {
        foreach (glob($this->config->root . '/data/calendars/*.{png,jpg}', GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < time() - 172800) {
                $this->db->deleteCache('calendar_' . pathinfo($file, PATHINFO_FILENAME));
                unlink($file);
            }
        }
        $this->db->run('DELETE FROM updates WHERE processed_at IS NOT NULL AND processed_at<?', [
            time() - 604800,
        ]);
        $this->db->run('DELETE FROM outbox WHERE sent_at IS NOT NULL AND sent_at<?', [
            time() - 604800,
        ]);
        $this->db->run('DELETE FROM sessions WHERE updated_at<?', [time() - 86400]);
        $this->db->cleanupCache(time() - 86400);
    }
}
