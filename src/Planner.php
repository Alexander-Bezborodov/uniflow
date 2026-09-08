<?php

declare(strict_types=1);

namespace UniFlow;

final class Planner
{
    public static function priority(array $t, \DateTimeImmutable $now): int
    {
        $h = (strtotime($t['deadline']) - $now->getTimestamp()) / 3600;
        if ($h <= 0) {
            return 5;
        }
        $base = $h <= 24 ? 4 : ($h <= 72 ? 3 : ($h <= 168 ? 2 : 1));
        return min(
            4,
            $base + ((int) $t['importance'] === 3 || (int) $t['estimated_minutes'] >= 180 ? 1 : 0),
        );
    }

    public static function minutes(int $minutes, string $deadline, \DateTimeImmutable $now): int
    {
        $d = (new \DateTimeImmutable($deadline))->setTimezone($now->getTimezone());
        $days = max(1, (int) $now->setTime(0, 0)->diff($d->setTime(0, 0))->format('%r%a'));
        if ($d->getTimestamp() - $now->getTimestamp() <= 86400 || $days === 1) {
            return $minutes;
        }
        return min($minutes, max(5, (int) ceil($minutes / $days)));
    }

    public static function plan(array $tasks, \DateTimeImmutable $now): array
    {
        usort($tasks, function ($a, $b) use ($now): int {
            return [
                -self::priority($a, $now),
                strtotime($a['deadline']),
                -(int) $a['importance'],
                -(int) $a['estimated_minutes'],
                (int) $a['id'],
            ] <=> [
                -self::priority($b, $now),
                strtotime($b['deadline']),
                -(int) $b['importance'],
                -(int) $b['estimated_minutes'],
                (int) $b['id'],
            ];
        });
        foreach ($tasks as &$t) {
            $t['minutes'] = self::minutes((int) $t['estimated_minutes'], $t['deadline'], $now);
        }
        unset($t);
        return $tasks;
    }

    public static function card(array $t, \DateTimeImmutable $now, string $language = 'ru'): string
    {
        $priority = $language === 'en'
            ? ['', '🟢 Low', '🟡 Medium', '🔴 High']
            : ['', '🟢 Низкий', '🟡 Средний', '🔴 Высокий'];
        $subject = isset($t['subject']) && trim((string) $t['subject']) !== ''
            ? "\n" .
                ($language === 'en' ? 'Subject: ' : 'Предмет: ') .
                Emojis::escape($t['subject'])
            : "\n";
        return '<b>' .
            Emojis::escape($t['title']) .
            '</b>' .
            $subject .
            "\n📅 " .
            (isset($t['deadline']) ? Dates::label($t['deadline'], $now) : ($language === 'en' ? 'Specify a deadline' : 'Уточни дедлайн')) .
            (!isset($t['duration_visible']) || !empty($t['duration_visible'])
                ? "\n⏱ " . ($t['estimated_minutes'] ?? '?') . ($language === 'en' ? ' min for the task' : ' мин на задачу')
                : '') .
            (!empty($t['importance_visible'])
                ? "\n" . $priority[(int) ($t['importance'] ?? 2)]
                : '');
    }

    public static function reasons(array $t, \DateTimeImmutable $now): array
    {
        $r = [strtotime($t['deadline']) - $now->getTimestamp() <= 259200 ? 'deadline' : 'prepare'];
        if ((int) $t['importance'] === 3) {
            $r[] = 'importance';
        }
        if ((int) $t['estimated_minutes'] >= 180) {
            $r[] = 'volume';
        }
        return $r;
    }

    public const REASONS = [
        'deadline' => 'Срок близко. Начни с этой задачи.',
        'prepare' => 'Начни заранее, чтобы распределить нагрузку.',
        'importance' => 'У задачи высокая важность.',
        'volume' => 'Задача объёмная. Лучше начать заранее.',
    ];
}
