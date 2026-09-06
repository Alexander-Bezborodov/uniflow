<?php

declare(strict_types=1);

namespace UniFlow;

final class Config
{
    private array $values;
    public string $root;

    public function __construct(string $root, ?array $values = null)
    {
        $this->root = $root;
        $this->values = $values ?? [];
        if ($values === null && is_file($root . '/.env')) {
            foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
                if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
                    continue;
                }
                $value = $m[2];
                if (
                    strlen($value) >= 2 &&
                    (($value[0] === '"' && substr($value, -1) === '"') ||
                        ($value[0] === "'" && substr($value, -1) === "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                $this->values[$m[1]] = $value;
            }
        }
        new \DateTimeZone($this->get('TIMEZONE', 'Asia/Yekaterinburg'));
    }

    public function get(string $key, string $default = ''): string
    {
        return trim((string) ($this->values[$key] ?? $default));
    }

    public function enabled(string $key, bool $default = false): bool
    {
        $v = strtolower($this->get($key, $default ? 'true' : 'false'));
        if (!in_array($v, ['true', 'false'], true)) {
            throw new \RuntimeException($key . ' must be true or false');
        }
        return $v === 'true';
    }

    public function path(string $key, string $default): string
    {
        $p = $this->get($key, $default);
        return $p[0] === '/' ? $p : $this->root . '/' . $p;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            'now',
            new \DateTimeZone($this->get('TIMEZONE', 'Asia/Yekaterinburg')),
        );
    }

    public function validate(): void
    {
        foreach (['curl', 'pdo_sqlite', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException('Missing extension: ' . $ext);
            }
        }
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $this->get('BOT_TOKEN'))) {
            throw new \RuntimeException('Set BOT_TOKEN in .env');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/', $this->get('WEBHOOK_SECRET'))) {
            throw new \RuntimeException('Set WEBHOOK_SECRET (32-256 characters)');
        }
    }
}
