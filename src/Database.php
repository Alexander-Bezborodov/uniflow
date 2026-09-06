<?php

declare(strict_types=1);

namespace UniFlow;

final class Database
{
    public \PDO $pdo;

    public function __construct(string $path)
    {
        if (
            !is_dir(dirname($path)) &&
            !mkdir(dirname($path), 0700, true) &&
            !is_dir(dirname($path))
        ) {
            throw new \RuntimeException('Cannot create data directory');
        }
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000;');
    }

    public function run(string $sql, array $args = []): \PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    public function one(string $sql, array $args = []): ?array
    {
        $r = $this->run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public function transaction(callable $fn)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $this->pdo->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function initialize(): void
    {
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->transaction(function (): void {
            $v = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
            if ($v > 2) {
                throw new \RuntimeException('Database version is newer than this bot');
            }
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
                telegram_id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, timezone TEXT NOT NULL,
                created_at DATETIME NOT NULL, reminders_disabled INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(telegram_id),
                title TEXT NOT NULL CHECK(length(trim(title)) BETWEEN 1 AND 200), subject TEXT,
                deadline DATETIME NOT NULL, estimated_minutes INTEGER NOT NULL DEFAULT 60 CHECK(estimated_minutes BETWEEN 1 AND 10080),
                importance INTEGER NOT NULL DEFAULT 2 CHECK(importance BETWEEN 1 AND 3),
                status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','completed')), created_at DATETIME NOT NULL,
                completed_at DATETIME, reminder_24h_sent INTEGER NOT NULL DEFAULT 0,
                reminder_3h_sent INTEGER NOT NULL DEFAULT 0, is_demo INTEGER NOT NULL DEFAULT 0);
                CREATE INDEX IF NOT EXISTS idx_tasks_user_status ON tasks(user_id,status);
                CREATE INDEX IF NOT EXISTS idx_tasks_deadline ON tasks(status,deadline);");
            foreach (
                [
                    'users' => ['timezone_confirmed', 'telegram_blocked', 'ai_consent'],
                    'tasks' => ['revision'],
                ]
                as $table => $columns
            ) {
                $existing = array_column(
                    $this->run('PRAGMA table_info(' . $table . ')')->fetchAll(),
                    'name',
                );
                foreach ($columns as $column) {
                    if (!in_array($column, $existing, true)) {
                        $this->pdo->exec(
                            "ALTER TABLE $table ADD COLUMN $column INTEGER NOT NULL DEFAULT 0",
                        );
                    }
                }
            }
            $this
                ->pdo->exec('CREATE TABLE IF NOT EXISTS sessions (user_id INTEGER PRIMARY KEY REFERENCES users(telegram_id), data TEXT NOT NULL, updated_at INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS updates (id INTEGER PRIMARY KEY, payload TEXT NOT NULL, received_at INTEGER NOT NULL, processed_at INTEGER, attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL DEFAULT 0);
                CREATE INDEX IF NOT EXISTS idx_updates_pending ON updates(processed_at,available_at,id);
                CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, method TEXT NOT NULL, payload TEXT NOT NULL, chat_id INTEGER, available_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, sent_at INTEGER, failed_at INTEGER, error_code INTEGER, task_id INTEGER, revision INTEGER, reminder_hours INTEGER);
                CREATE UNIQUE INDEX IF NOT EXISTS idx_reminder_unique ON outbox(task_id,revision,reminder_hours);
                CREATE INDEX IF NOT EXISTS idx_outbox_pending ON outbox(sent_at,failed_at,available_at,id);
                CREATE INDEX IF NOT EXISTS idx_outbox_chat ON outbox(chat_id,sent_at,failed_at,id);
                CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT NOT NULL);
                PRAGMA user_version=2;');
        });
    }

    public function enqueue(array $update): void
    {
        $this->run('INSERT OR IGNORE INTO updates(id,payload,received_at) VALUES(?,?,?)', [
            $update['update_id'],
            json_encode($update, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            time(),
        ]);
    }

    public function state(int $id): array
    {
        $r = $this->one('SELECT * FROM sessions WHERE user_id=?', [$id]);
        return $r && (int) $r['updated_at'] > time() - 86400
            ? json_decode($r['data'], true, 64, JSON_THROW_ON_ERROR)
            : [];
    }

    public function saveState(int $id, array $s): void
    {
        $this->run(
            'INSERT INTO sessions(user_id,data,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at',
            [$id, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time()],
        );
    }

    public function send(int $id, string $text, ?array $keyboard = null): void
    {
        $p = ['chat_id' => $id, 'text' => Emojis::render($text), 'parse_mode' => 'HTML'];
        if ($keyboard !== null) {
            $p['reply_markup'] = $keyboard;
        }
        $this->out('sendMessage', $p);
    }

    public function out(string $method, array $payload, ?array $reminder = null): void
    {
        $this->run(
            'INSERT OR IGNORE INTO outbox(method,payload,chat_id,task_id,revision,reminder_hours) VALUES(?,?,?,?,?,?)',
            [
                $method,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $payload['chat_id'] ?? null,
                $reminder['id'] ?? null,
                $reminder['revision'] ?? null,
                $reminder['hours'] ?? null,
            ],
        );
    }

    public function tasks(int $id, bool $active = true): array
    {
        return $this->run(
            'SELECT * FROM tasks WHERE user_id=?' .
                ($active ? " AND status='active'" : '') .
                ' ORDER BY deadline,id',
            [$id],
        )->fetchAll();
    }

    public function task(int $user, int $id): ?array
    {
        return $this->one("SELECT * FROM tasks WHERE user_id=? AND id=? AND status='active'", [
            $user,
            $id,
        ]);
    }

    public function create(
        int $user,
        array $draft,
        \DateTimeImmutable $now,
        bool $demo = false
    ): void {
        Dates::validate($draft, $now);
        $this->run(
            'INSERT INTO tasks(user_id,title,subject,deadline,estimated_minutes,importance,created_at,is_demo) VALUES(?,?,?,?,?,?,?,?)',
            [
                $user,
                $draft['title'],
                $draft['subject'] ?? null,
                $draft['deadline'],
                $draft['estimated_minutes'],
                $draft['importance'],
                Dates::utc($now),
                (int) $demo,
            ],
        );
    }
}
