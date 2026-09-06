<?php

declare(strict_types=1);

namespace UniFlow;

final class Dates
{
    public const MONTHS = [
        'января',
        'февраля',
        'марта',
        'апреля',
        'мая',
        'июня',
        'июля',
        'августа',
        'сентября',
        'октября',
        'ноября',
        'декабря',
    ];
    public const DAY = 'понедельник\w*|вторник\w*|сред[ауы]|четверг\w*|пятниц\w*|суббот\w*|воскресень\w*';
    public const DURATION = '~(?<![\w.,-])(?:\d+(?:[.,]\d+)?\s*(?:час(?:а|ов)?|ч\.?|минут(?:а|ы|у)?|мин\.?)|полтора\s+часа|полчаса|час)(?!\w)~iu';

    public static function pattern(): string
    {
        return '(?:через\s+\d+\s+(?:дня|дней|день|неделю|недели|недель)|послезавтра|завтра|сегодня|вчера|(?:следующ\w*\s+)?(?:' .
            self::DAY .
            ')|\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}(?:\.\d{4})?|\d{1,2}\s+(?:' .
            implode('|', self::MONTHS) .
            ')(?:\s+\d{4})?)(?:\s*(?:в|к)?\s*\d{1,2}:\d{2})?';
    }

    public static function utc(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function label(string $iso, \DateTimeImmutable $now): string
    {
        return (new \DateTimeImmutable($iso))
            ->setTimezone($now->getTimezone())
            ->format('d.m.Y H:i');
    }

    public static function parse(string $text, \DateTimeImmutable $now): ?string
    {
        $s = preg_replace('~^(?:до|к|в)\s+~u', '', mb_strtolower(trim($text)));
        if (!preg_match('~^' . self::pattern() . '$~uD', $s)) {
            return null;
        }
        $h = 23;
        $min = 59;
        if (preg_match('~\s*(?:в|к)?\s*(\d{1,2}):(\d{2})$~u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $h = (int) $m[1][0];
            $min = (int) $m[2][0];
            $s = trim(substr($s, 0, $m[0][1]));
        }
        if ($h > 23 || $min > 59) {
            return null;
        }
        $relative = ['сегодня' => 0, 'завтра' => 1, 'послезавтра' => 2, 'вчера' => -1];
        if (isset($relative[$s])) {
            $d = $now->modify(sprintf('%+d days', $relative[$s]));
        } elseif (preg_match('~^через\s+(\d+)\s+(\w+)$~u', $s, $m)) {
            $days = (int) $m[1] * (strpos($m[2], 'недел') === 0 ? 7 : 1);
            if ($days > 3660) {
                return null;
            }
            $d = $now->modify('+' . $days . ' days');
        } elseif (preg_match('~^(?:следующ\w*\s+)?(?:' . self::DAY . ')$~u', $s)) {
            $stems = [
                'понедельник',
                'вторник',
                'сред',
                'четверг',
                'пятниц',
                'суббот',
                'воскресень',
            ];
            foreach ($stems as $i => $stem) {
                if (strpos($s, $stem) !== false) {
                    $delta = ($i + 1 - (int) $now->format('N') + 7) % 7;
                    break;
                }
            }
            if ($delta === 0 && strpos($s, 'следующ') !== false) {
                $delta = 7;
            }
            $d = $now->modify('+' . $delta . ' days');
        } else {
            $year = null;
            if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $s, $m)) {
                $year = (int) $m[1];
                $month = (int) $m[2];
                $day = (int) $m[3];
            } elseif (preg_match('~^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?$~', $s, $m)) {
                $day = (int) $m[1];
                $month = (int) $m[2];
                $year = isset($m[3]) ? (int) $m[3] : null;
            } else {
                preg_match(
                    '~^(\d{1,2})\s+(' . implode('|', self::MONTHS) . ')(?:\s+(\d{4}))?$~u',
                    $s,
                    $m,
                );
                $day = (int) $m[1];
                $month = array_search($m[2], self::MONTHS, true) + 1;
                $year = isset($m[3]) ? (int) $m[3] : null;
            }
            $d = null;
            for (
                $y = $year ?? (int) $now->format('Y'), $last = $year ?? $y + 8;
                $y <= $last;
                $y++
            ) {
                if (!checkdate($month, $day, $y)) {
                    continue;
                }
                $candidate = $now->setDate($y, $month, $day);
                if ($year !== null || $candidate->format('Y-m-d') >= $now->format('Y-m-d')) {
                    $d = $candidate;
                    break;
                }
            }
            if ($d === null) {
                return null;
            }
        }
        $expected = $d->format('Y-m-d') . sprintf(' %02d:%02d', $h, $min);
        $d = $d->setTime($h, $min, 0, 0);
        return $d->format('Y-m-d H:i') === $expected ? self::utc($d) : null;
    }

    public static function duration(string $s): ?int
    {
        $s = preg_replace('~^(?:примерно|около|подготовка)\s+~u', '', mb_strtolower(trim($s)));
        if (ctype_digit($s)) {
            $n = (float) $s;
        } else {
            preg_match_all(self::DURATION, $s, $m);
            $rest = trim(preg_replace(self::DURATION, '', $s), " ,+\t");
            if (!$m[0] || ($rest !== '' && $rest !== 'и')) {
                return null;
            }
            $n = 0;
            foreach ($m[0] as $part) {
                if ($part === 'полчаса') {
                    $n += 30;
                } elseif (preg_match('~^полтора~u', $part)) {
                    $n += 90;
                } elseif ($part === 'час') {
                    $n += 60;
                } else {
                    $n +=
                        (float) str_replace(',', '.', $part) *
                        (strpos($part, 'ч') !== false ? 60 : 1);
                }
            }
        }
        return $n > 0 && $n <= 10080 ? (int) ceil($n) : null;
    }

    public static function validate(array $t, \DateTimeImmutable $now): void
    {
        if (
            !isset($t['title']) ||
            !is_string($t['title']) ||
            mb_strlen(trim($t['title'])) < 1 ||
            mb_strlen($t['title']) > 200
        ) {
            throw new \InvalidArgumentException('Название: от 1 до 200 символов.');
        }
        if (
            isset($t['subject']) &&
            (!is_string($t['subject']) ||
                mb_strlen($t['subject']) > 100 ||
                trim($t['subject']) === '')
        ) {
            throw new \InvalidArgumentException('Предмет: от 1 до 100 символов.');
        }
        if (
            !isset($t['deadline']) ||
            !is_string($t['deadline']) ||
            !preg_match(
                '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$~D',
                $t['deadline'],
            ) ||
            strtotime($t['deadline']) <= $now->getTimestamp()
        ) {
            throw new \InvalidArgumentException('Укажи будущий дедлайн.');
        }
        if (
            !isset($t['estimated_minutes']) ||
            !is_int($t['estimated_minutes']) ||
            $t['estimated_minutes'] < 1 ||
            $t['estimated_minutes'] > 10080
        ) {
            throw new \InvalidArgumentException('Время: от 1 до 10080 минут.');
        }
        if (
            !isset($t['importance']) ||
            !is_int($t['importance']) ||
            $t['importance'] < 1 ||
            $t['importance'] > 3
        ) {
            throw new \InvalidArgumentException('Важность: от 1 до 3.');
        }
    }
}
