<?php

declare(strict_types=1);

namespace UniFlow;

final class TaskCalendar
{
    public static function prepare(Config $config, Database $db, Telegram $telegram, int $user, string $month): string
    {
        if (!extension_loaded('gd') || !function_exists('imagettftext')) {
            throw new \RuntimeException('Calendar requires PHP GD with FreeType');
        }
        $profile = $db->one('SELECT timezone,language FROM users WHERE telegram_id=?', [$user]);
        if (!$profile) {
            throw new \RuntimeException('Calendar profile missing');
        }
        $now = $config->now()->setTimezone(new \DateTimeZone($profile['timezone']));
        $tasks = $db->tasks($user);
        $key = hash_hmac('sha256', json_encode([6, $user, $month, $profile, $now->format('Y-m-d'), $tasks]), $config->get('BOT_TOKEN'));
        $dir = $config->root . '/data/calendars';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Calendar cache unavailable');
        }
        $file = $dir . '/' . $key . '.jpg';
        $cached = $db->getCache('calendar_' . $key);
        if ($cached && is_file($file) && filemtime($file) > time() - 86400) {
            return $cached['value'];
        }
        try {
            $png = self::render($tasks, $now, $month, $profile['language'], $config->root . '/public/assets/fonts/NotoSans.ttf');
        } catch (\Throwable $e) {
            throw new \RuntimeException('calendar_render_failed', 0, $e);
        }
        $tmp = tempnam($dir, 'tmp');
        if ($tmp === false || file_put_contents($tmp, $png) === false || !rename($tmp, $file)) {
            throw new \RuntimeException('Calendar cache write failed');
        }
        $target = $config->get('CALENDAR_PUBLIC_URL') . '?key=' . $key;
        try {
            $url = $telegram->createIncomingWebhookUrl($target);
        } catch (\Throwable $e) {
            throw new \RuntimeException('calendar_relay_failed', 0, $e);
        }
        $db->setCache('calendar_' . $key, $url);
        return $url;
    }

    public static function render(array $tasks, \DateTimeImmutable $now, string $month, string $language, string $font): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException('Invalid calendar month');
        }
        $start = new \DateTimeImmutable($month . '-01', $now->getTimezone());
        $offset = (int) $start->format('N') - 1;
        $weeks = (int) ceil(($offset + (int) $start->format('t')) / 7);
        $height = 240 + $weeks * 146 + 72;
        $im = imagecreatetruecolor(1400, $height);
        $color = function (string $hex) use ($im): int {
            return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        };
        $bg = $color('F8F5F0');
        $white = $color('FFFFFF');
        $ink = $color('302A29');
        $muted = $color('928782');
        $red = $color('941E25');
        $line = $color('E9E2DA');
        $pink = $color('F8E8E5');
        $green = $color('E8F0E9');
        $orange = $color('FAEAD8');
        imagefill($im, 0, 0, $bg);
        imageantialias($im, true);
        $round = function (int $x, int $y, int $w, int $h, int $r, int $tone) use ($im): void {
            imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $tone);
            imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $tone);
            foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as $corner) {
                imagefilledellipse($im, $corner[0], $corner[1], $r * 2, $r * 2, $tone);
            }
        };
        $text = function (int $x, int $y, string $value, int $size, int $tone, int $max = 0) use ($im, $font): void {
            if ($max > 0) {
                $original = $value;
                while (mb_strlen($value) > 0) {
                    $candidate = $value . ($value !== $original ? '…' : '');
                    $box = imagettfbbox($size, 0, $font, $candidate);
                    if ($box[2] - $box[0] <= $max) {
                        $value = $candidate;
                        break;
                    }
                    $value = mb_substr($value, 0, -1);
                }
            }
            imagettftext($im, $size, 0, $x, $y, $tone, $font, $value);
        };
        $qrPath = dirname($font, 2) . '/uniflow-qr.png';
        if (is_file($qrPath)) {
            $round(1230, 24, 120, 120, 12, $white);
            $qr = imagecreatefrompng($qrPath);
            $scale = min(108 / imagesx($qr), 108 / imagesy($qr));
            $width = (int) round(imagesx($qr) * $scale);
            $heightQr = (int) round(imagesy($qr) * $scale);
            imagecopyresized($im, $qr, 1236 + intdiv(108 - $width, 2), 30 + intdiv(108 - $heightQr, 2), 0, 0, $width, $heightQr, imagesx($qr), imagesy($qr));
            if (PHP_VERSION_ID < 80000) {
                imagedestroy($qr);
            }
        }
        $text(48, 88, 'UniFlow', 40, $red);
        $text(49, 88, 'UniFlow', 40, $red);
        $text(50, 88, 'UniFlow', 40, $red);
        $en = $language === 'en';
        $text(790, 81, $en ? 'YOUR TASK CALENDAR' : 'КАЛЕНДАРЬ ЗАДАЧ', 17, $muted);
        $months = $en
            ? ['January','February','March','April','May','June','July','August','September','October','November','December']
            : ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
        $text(48, 167, $months[(int) $start->format('n') - 1] . ' ' . $start->format('Y'), 30, $ink);
        $grouped = [];
        foreach ($tasks as $task) {
            $date = (new \DateTimeImmutable($task['deadline']))->setTimezone($now->getTimezone());
            if ($date->format('Y-m') === $month) {
                $task['clock'] = $date->format('H:i');
                $grouped[(int) $date->format('j')][] = $task;
            }
        }
        $count = array_sum(array_map('count', $grouped));
        $text(790, 161, ($en ? 'Tasks: ' : 'Задач: ') . $count, 21, $red);
        $days = $en ? ['MON','TUE','WED','THU','FRI','SAT','SUN'] : ['ПН','ВТ','СР','ЧТ','ПТ','СБ','ВС'];
        foreach ($days as $i => $day) {
            $text(60 + $i * 187, 216, $day, 18, $i >= 5 ? $red : $muted);
        }
        for ($cell = 0; $cell < $weeks * 7; $cell++) {
            $x = 48 + ($cell % 7) * 187;
            $y = 236 + intdiv($cell, 7) * 146;
            $date = $start->modify(($cell - $offset) . ' days');
            $current = $date->format('Y-m') === $month;
            $today = $date->format('Y-m-d') === $now->format('Y-m-d');
            $round($x, $y, 179, 138, 16, $today ? $red : $line);
            $round($x + 2, $y + 2, 175, 134, 14, $current ? $white : $bg);
            $text($x + 12, $y + 29, $date->format('j'), 21, $today ? $red : ($current ? $ink : $muted));
            if (!$current) {
                continue;
            }
            $events = $grouped[(int) $date->format('j')] ?? [];
            foreach (array_slice($events, 0, 2) as $i => $event) {
                $ey = $y + 35 + $i * 42;
                $priority = !empty($event['importance_visible']) ? (int) $event['importance'] : 0;
                $round($x + 8, $ey, 163, 40, 9, $priority === 3 ? $pink : ($priority === 2 ? $orange : $green));
                $label = $event['clock'] === '23:59' ? ($en ? 'All day' : 'В течение дня') : $event['clock'];
                $text($x + 14, $ey + 16, $label, 11, $muted);
                $text($x + 14, $ey + 34, preg_replace('/\s+/u', ' ', $event['title']), 13, $ink, 149);
            }
            if (count($events) > 2) {
                $text($x + 12, $y + 132, '+' . (count($events) - 2) . ($en ? ' more' : ' ещё'), 10, $red);
            }
        }
        $text(48, $height - 27, $en ? 'One step at a time.' : 'Всё по плану. Шаг за шагом.', 15, $muted);
        $text(1020, $height - 27, $en ? 'Details in the list below' : 'Подробности в списке ниже', 13, $muted);
        $small = imagescale($im, 1120);
        imageinterlace($small, PHP_VERSION_ID < 80000 ? 1 : true);
        ob_start();
        try {
            if (!imagejpeg($small, null, 60)) {
                throw new \RuntimeException('calendar_jpeg_failed');
            }
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($small);
            imagedestroy($im);
        }
        return $png;
    }
}
