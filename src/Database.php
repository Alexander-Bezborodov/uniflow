<?php

declare(strict_types=1);

namespace UniFlow;

final class Database
{
    public const SCHEMA_VERSION = 5;

    public \PDO $pdo;
    private bool $mysql = false;

    public function __construct($source)
    {
        if ($source instanceof Config) {
            $this->mysql = true;
            $settings = self::settings($source);
            $dsn =
                'mysql:host=' .
                $settings['host'] .
                ';port=' .
                $settings['port'] .
                ';dbname=' .
                $settings['name'] .
                ';charset=utf8mb4';
            $this->pdo = new \PDO($dsn, $settings['user'], $settings['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return;
        }
        $path = (string) $source;
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

    private static function settings(Config $config): array
    {
        $settings = [
            'host' => $config->get('DB_HOST'),
            'port' => $config->get('DB_PORT', '3306'),
            'name' => $config->get('DB_NAME'),
            'user' => $config->get('DB_USER'),
            'pass' => $config->get('DB_PASS'),
        ];
        if ($settings['host'] === '' || preg_match('/[\r\n;]/', $settings['host'])) {
            throw new \RuntimeException('Set DB_HOST in .env');
        }
        if (
            !ctype_digit($settings['port']) ||
            (int) $settings['port'] < 1 ||
            (int) $settings['port'] > 65535
        ) {
            throw new \RuntimeException('Set valid DB_PORT in .env');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $settings['name'])) {
            throw new \RuntimeException('Set valid DB_NAME in .env');
        }
        if ($settings['user'] === '' || preg_match('/[\r\n]/', $settings['user'])) {
            throw new \RuntimeException('Set DB_USER in .env');
        }
        if (preg_match('/[\r\n]/', $settings['pass'])) {
            throw new \RuntimeException('Set valid DB_PASS in .env');
        }
        return $settings;
    }

    public static function createDatabase(Config $config): void
    {
        $settings = self::settings($config);
        $dsn =
            'mysql:host=' .
            $settings['host'] .
            ';port=' .
            $settings['port'] .
            ';charset=utf8mb4';
        $pdo = new \PDO($dsn, $settings['user'], $settings['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try {
            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS `' .
                    $settings['name'] .
                    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            );
        } catch (\PDOException $e) {
            new self($config);
        }
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
        $this->mysql ? $this->pdo->beginTransaction() : $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $this->mysql ? $this->pdo->commit() : $this->pdo->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            if ($this->mysql && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$this->mysql) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public function initialize(): void
    {
        if ($this->mysql) {
            $this->initializeMariaDb();
            return;
        }
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->transaction(function (): void {
            $v = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
            if ($v > self::SCHEMA_VERSION) {
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
                reminder_3h_sent INTEGER NOT NULL DEFAULT 0, overdue_sent INTEGER NOT NULL DEFAULT 0,
                is_demo INTEGER NOT NULL DEFAULT 0);
                CREATE INDEX IF NOT EXISTS idx_tasks_user_status ON tasks(user_id,status);
                CREATE INDEX IF NOT EXISTS idx_tasks_deadline ON tasks(status,deadline);");
            foreach (
                [
                    'users' => ['timezone_confirmed', 'telegram_blocked', 'ai_consent'],
                    'tasks' => ['revision', 'importance_visible', 'overdue_sent'],
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
            $userColumns = array_column(
                $this->run('PRAGMA table_info(users)')->fetchAll(),
                'name',
            );
            if (!in_array('language', $userColumns, true)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN language TEXT NOT NULL DEFAULT 'ru'");
            }
            $taskColumns = array_column(
                $this->run('PRAGMA table_info(tasks)')->fetchAll(),
                'name',
            );
            if (!in_array('duration_visible', $taskColumns, true)) {
                $this->pdo->exec('ALTER TABLE tasks ADD COLUMN duration_visible INTEGER NOT NULL DEFAULT 1');
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
                PRAGMA user_version=5;');
            $this->pdo->exec('UPDATE users SET username=NULL');
        });
    }

    private function initializeMariaDb(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS schema_meta (
                id TINYINT UNSIGNED PRIMARY KEY,
                version INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS users (
                telegram_id BIGINT PRIMARY KEY,
                username VARCHAR(255) NULL,
                full_name VARCHAR(255) NULL,
                timezone VARCHAR(64) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                reminders_disabled TINYINT(1) NOT NULL DEFAULT 0,
                timezone_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                telegram_blocked TINYINT(1) NOT NULL DEFAULT 0,
                ai_consent TINYINT(1) NOT NULL DEFAULT 0,
                language VARCHAR(2) NOT NULL DEFAULT 'ru'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                title VARCHAR(200) NOT NULL,
                subject VARCHAR(100) NULL,
                deadline VARCHAR(40) NOT NULL,
                estimated_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                importance TINYINT UNSIGNED NOT NULL DEFAULT 2,
                duration_visible TINYINT(1) NOT NULL DEFAULT 1,
                importance_visible TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at VARCHAR(40) NOT NULL,
                completed_at VARCHAR(40) NULL,
                reminder_24h_sent TINYINT(1) NOT NULL DEFAULT 0,
                reminder_3h_sent TINYINT(1) NOT NULL DEFAULT 0,
                overdue_sent TINYINT(1) NOT NULL DEFAULT 0,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                revision INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tasks_user_status (user_id, status),
                INDEX idx_tasks_deadline (status, deadline),
                CONSTRAINT fk_tasks_user FOREIGN KEY (user_id)
                    REFERENCES users(telegram_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS sessions (
                user_id BIGINT PRIMARY KEY,
                data LONGTEXT NOT NULL,
                updated_at BIGINT NOT NULL,
                CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
                    REFERENCES users(telegram_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS updates (
                id BIGINT PRIMARY KEY,
                payload LONGTEXT NOT NULL,
                received_at BIGINT NOT NULL,
                processed_at BIGINT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                available_at BIGINT NOT NULL DEFAULT 0,
                INDEX idx_updates_pending (processed_at, available_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                method VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                chat_id BIGINT NULL,
                available_at BIGINT NOT NULL DEFAULT 0,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                sent_at BIGINT NULL,
                failed_at BIGINT NULL,
                error_code INT NULL,
                task_id BIGINT UNSIGNED NULL,
                revision INT UNSIGNED NULL,
                reminder_hours TINYINT UNSIGNED NULL,
                UNIQUE KEY idx_reminder_unique (task_id, revision, reminder_hours),
                INDEX idx_outbox_pending (sent_at, failed_at, available_at, id),
                INDEX idx_outbox_chat (chat_id, sent_at, failed_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cache (
                cache_key VARCHAR(191) PRIMARY KEY,
                value LONGTEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
        $columns = $this->run(
            'SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name=? AND column_name=?) OR (table_name=? AND column_name=?))',
            ['users', 'language', 'tasks', 'duration_visible'],
        )->fetchAll();
        $existing = [];
        foreach ($columns as $column) {
            $existing[$column['table_name'] . '.' . $column['column_name']] = true;
        }
        if (empty($existing['users.language'])) {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'ru'");
        }
        if (empty($existing['tasks.duration_visible'])) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN duration_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER estimated_minutes');
        }
        $overdueColumn = $this->one(
            'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['tasks', 'overdue_sent'],
        );
        if (!$overdueColumn) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN overdue_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_3h_sent');
        }
        $version = $this->one('SELECT version FROM schema_meta WHERE id=1');
        if ($version && (int) $version['version'] > self::SCHEMA_VERSION) {
            throw new \RuntimeException('Database version is newer than this bot');
        }
        $this->run(
            'INSERT INTO schema_meta(id,version) VALUES(1,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',
            [self::SCHEMA_VERSION],
        );
        $this->pdo->exec('UPDATE users SET username=NULL');
    }

    public function initialized(): bool
    {
        if (!$this->mysql) {
            return (int) $this->pdo
                    ->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='updates'")
                    ->fetchColumn() === 1;
        }
        return (int) $this
                ->run(
                    'SELECT count(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
                    ['updates'],
                )
                ->fetchColumn() === 1;
    }

    public function importSqlite(string $path): int
    {
        if (!$this->mysql || !is_file($path)) {
            return 0;
        }
        if ((int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn() > 0) {
            return 0;
        }
        $source = new self($path);
        if (!$source->initialized()) {
            return 0;
        }
        $users = $source->run('SELECT * FROM users')->fetchAll();
        $tasks = $source->run('SELECT * FROM tasks')->fetchAll();
        $this->transaction(function () use ($users, $tasks): void {
            foreach ($users as $user) {
                $this->run(
                    'INSERT IGNORE INTO users(telegram_id,username,full_name,timezone,created_at,reminders_disabled,timezone_confirmed,telegram_blocked,ai_consent,language) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [
                        $user['telegram_id'],
                        null,
                        $user['full_name'] ?? null,
                        $user['timezone'] ?? 'Asia/Yekaterinburg',
                        $user['created_at'],
                        (int) ($user['reminders_disabled'] ?? 0),
                        (int) ($user['timezone_confirmed'] ?? 0),
                        (int) ($user['telegram_blocked'] ?? 0),
                        (int) ($user['ai_consent'] ?? 0),
                        in_array($user['language'] ?? 'ru', ['ru', 'en'], true) ? $user['language'] : 'ru',
                    ],
                );
            }
            foreach ($tasks as $task) {
                $this->run(
                    'INSERT IGNORE INTO tasks(id,user_id,title,subject,deadline,estimated_minutes,duration_visible,importance,importance_visible,status,created_at,completed_at,reminder_24h_sent,reminder_3h_sent,is_demo,revision) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $task['id'],
                        $task['user_id'],
                        $task['title'],
                        $task['subject'] ?? null,
                        $task['deadline'],
                        (int) ($task['estimated_minutes'] ?? 60),
                        (int) ($task['duration_visible'] ?? 1),
                        (int) ($task['importance'] ?? 2),
                        (int) ($task['importance_visible'] ?? 0),
                        $task['status'] ?? 'active',
                        $task['created_at'],
                        $task['completed_at'] ?? null,
                        (int) ($task['reminder_24h_sent'] ?? 0),
                        (int) ($task['reminder_3h_sent'] ?? 0),
                        (int) ($task['is_demo'] ?? 0),
                        (int) ($task['revision'] ?? 0),
                    ],
                );
            }
        });
        return count($tasks);
    }

    public function setCache(string $key, string $value): void
    {
        $sql = $this->mysql
            ? 'INSERT INTO cache(cache_key,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)'
            : 'INSERT INTO cache(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value';
        $this->run($sql, [$key, $value]);
    }

    public function getCache(string $key): ?array
    {
        return $this->one(
            $this->mysql ? 'SELECT value FROM cache WHERE cache_key=?' : 'SELECT value FROM cache WHERE key=?',
            [$key],
        );
    }

    public function deleteCache(string $key): void
    {
        $this->run(
            $this->mysql ? 'DELETE FROM cache WHERE cache_key=?' : 'DELETE FROM cache WHERE key=?',
            [$key],
        );
    }

    public function deleteUserData(int $user): void
    {
        foreach ($this->run('SELECT id,payload FROM updates')->fetchAll() as $update) {
            $payload = json_decode($update['payload'], true);
            $from = $payload['message']['from']['id'] ?? $payload['callback_query']['from']['id'] ?? null;
            if ((int) $from === $user) {
                $this->run('DELETE FROM updates WHERE id=?', [$update['id']]);
            }
        }
        $this->run("DELETE FROM outbox WHERE chat_id=? AND method<>'answerCallbackQuery'", [$user]);
        $this->run('DELETE FROM sessions WHERE user_id=?', [$user]);
        $this->run('DELETE FROM tasks WHERE user_id=?', [$user]);
        $this->run('DELETE FROM users WHERE telegram_id=?', [$user]);
        $this->deleteCache('ai_rate_' . $user);
        $this->deleteCache('calendar_disabled_' . $user);
    }

    public function cleanupCache(int $before): void
    {
        $column = $this->mysql ? 'cache_key' : 'key';
        $type = $this->mysql ? 'UNSIGNED' : 'INTEGER';
        $this->run(
            "DELETE FROM cache WHERE $column LIKE 'ai_rate_%' AND CAST(value AS $type)<?",
            [$before],
        );
    }

    public function upsertUser(array $values): void
    {
        $sql = $this->mysql
            ? 'INSERT INTO users(telegram_id,username,full_name,timezone,created_at,timezone_confirmed) VALUES(?,?,?,?,?,0) ON DUPLICATE KEY UPDATE username=VALUES(username),full_name=VALUES(full_name),telegram_blocked=0'
            : 'INSERT INTO users(telegram_id,username,full_name,timezone,created_at,timezone_confirmed) VALUES(?,?,?,?,?,0) ON CONFLICT(telegram_id) DO UPDATE SET username=excluded.username,full_name=excluded.full_name,telegram_blocked=0';
        $this->run($sql, $values);
    }

    public function enqueue(array $update): void
    {
        unset($update['message']['from']['username']);
        unset($update['message']['chat']['username']);
        unset($update['callback_query']['from']['username']);
        unset($update['callback_query']['message']['from']['username']);
        unset($update['callback_query']['message']['chat']['username']);
        $sql = $this->mysql
            ? 'INSERT IGNORE INTO updates(id,payload,received_at) VALUES(?,?,?)'
            : 'INSERT OR IGNORE INTO updates(id,payload,received_at) VALUES(?,?,?)';
        $this->run($sql, [
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
        $sql = $this->mysql
            ? 'INSERT INTO sessions(user_id,data,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=VALUES(updated_at)'
            : 'INSERT INTO sessions(user_id,data,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at';
        $this->run(
            $sql,
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

    public function sendRich(int $id, string $markdown, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $id,
            'rich_message' => ['markdown' => $markdown],
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }
        $this->out('sendRichMessage', $payload);
    }

    public function sendPhoto(
        int $id,
        string $photo,
        string $caption,
        ?array $keyboard = null
    ): void {
        $payload = [
            'chat_id' => $id,
            'photo' => $photo,
            'caption' => Emojis::render($caption),
            'parse_mode' => 'HTML',
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }
        $this->out('sendPhoto', $payload);
    }

    public function out(string $method, array $payload, ?array $reminder = null): void
    {
        $sql = $this->mysql
            ? 'INSERT IGNORE INTO outbox(method,payload,chat_id,task_id,revision,reminder_hours) VALUES(?,?,?,?,?,?)'
            : 'INSERT OR IGNORE INTO outbox(method,payload,chat_id,task_id,revision,reminder_hours) VALUES(?,?,?,?,?,?)';
        $this->run(
            $sql,
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
    ): int {
        Dates::validate($draft, $now);
        $this->run(
            'INSERT INTO tasks(user_id,title,subject,deadline,estimated_minutes,duration_visible,importance,importance_visible,created_at,is_demo) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [
                $user,
                $draft['title'],
                $draft['subject'] ?? null,
                $draft['deadline'],
                $draft['estimated_minutes'],
                (int) ($draft['duration_visible'] ?? 1),
                $draft['importance'],
                (int) ($draft['importance_visible'] ?? 0),
                Dates::utc($now),
                (int) $demo,
            ],
        );
        return (int) $this->pdo->lastInsertId();
    }
}
