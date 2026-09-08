<?php

declare(strict_types=1);

namespace UniFlow;

final class Emojis
{
    public const MAP = [
        '📚' => ['5258328383183396223', '📖'],
        '📖' => ['5258328383183396223', '📖'],
        '📋' => ['5258328383183396223', '📖'],
        '📅' => ['4904882772637648609', '⏰'],
        '⏰' => ['4904882772637648609', '⏰'],
        '⏱' => ['5123230779593196220', '⏰'],
        '⏳' => ['5122933683820430249', '⭕️'],
        '➕' => ['5258043150110301407', '⬆️'],
        '📊' => ['5231200819986047254', '📊'],
        '❓' => ['5436113877181941026', '❓'],
        '⚙' => ['5258096772776991776', '⚙'],
        '✨' => ['5258165702707125574', '⭐'],
        '🎓' => ['5258328383183396223', '📖'],
        '✅' => ['5357069174512303778', '✅'],
        '❌' => ['5260342697075416641', '❌'],
        '🗑' => ['5260342697075416641', '❌'],
        '✏' => ['5258215635996908355', '✏️'],
        '🖌' => ['5258215635996908355', '✏️'],
        '🔎' => ['5429571366384842791', '🔎'],
        '⚠' => ['5447644880824181073', '⚠️'],
        '🚨' => ['5420323339723881652', '⚠️'],
        '🟢' => ['5323787447165787608', '🟢'],
        '🟡' => ['5325847502459510520', '🟠'],
        '🟠' => ['5325847502459510520', '🟠'],
        '🔴' => ['5323423027780656841', '🔴'],
        '🎯' => ['5258461531464539536', '📌'],
        '📌' => ['5258461531464539536', '📌'],
        '🤖' => ['5258093637450866522', '🤖'],
        '➡' => ['5341805749800546386', '➡️'],
        '⬅' => ['5339086588825580376', '⬅️'],
        '🔄' => ['5258420634785947640', '🔄'],
        '💬' => ['5260535596941582167', '💬'],
        'ℹ' => ['5258503720928288433', 'ℹ️'],
    ];

    public static function normalize(string $s): string
    {
        return str_replace(['—', '–'], '-', $s);
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars(self::normalize($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(string $s): string
    {
        $s = self::normalize($s);

        $pattern =
            '~(<tg-emoji\b[^>]*>.*?</tg-emoji>|<[^>]+>)|(' .
            implode(
                '|',
                array_map(function ($e) {
                    return preg_quote($e, '~');
                }, array_keys(self::MAP)),
            ) .
            ')\x{FE0F}?~u';
        return preg_replace_callback(
            $pattern,
            function (array $m): string {
                if ($m[1] !== '') {
                    return $m[0];
                }
                $e = self::MAP[$m[2]];
                return '<tg-emoji emoji-id="' . $e[0] . '">' . $e[1] . '</tg-emoji>';
            },
            $s,
        );
    }

    public static function button(string $label, ?string $callback = null): array
    {
        $b = ['text' => self::normalize($label)];
        foreach (self::MAP as $emoji => $pair) {
            if (strpos($label, $emoji) === 0) {
                $b['text'] = trim(str_replace("\u{FE0F}", '', substr($label, strlen($emoji))));
                $b['icon_custom_emoji_id'] = $pair[0];
                break;
            }
        }
        if ($callback !== null) {
            $b['callback_data'] = $callback;
        }
        return $b;
    }

    public static function inline(array $rows): array
    {
        return [
            'inline_keyboard' => array_map(function (array $row): array {
                return array_map(function (array $b): array {
                    return self::button($b[0], $b[1]);
                }, $row);
            }, $rows),
        ];
    }

    public static function keyboard(array $rows): array
    {
        return [
            'keyboard' => array_map(function (array $row): array {
                return array_map(function (string $s): array {
                    return self::button($s);
                }, $row);
            }, $rows),
            'resize_keyboard' => true,
        ];
    }

    public static function menu(bool $showExplain = false, string $language = 'ru'): array
    {
        $rows = $language === 'en'
            ? [
                ['🎯 Today', '➕ Add task'],
                ['📚 All tasks'],
                ['⚙ Settings', '📊 Statistics'],
                ['❓ Help'],
            ]
            : [
                ['🎯 Сегодня', '➕ Добавить задание'],
                ['📚 Все задачи'],
                ['⚙ Настройки', '📊 Статистика'],
                ['❓ Помощь'],
            ];
        if ($showExplain) {
            array_splice($rows, 2, 0, [[$language === 'en' ? '✨ Explain plan' : '✨ Объяснить план']]);
        }
        return self::keyboard($rows);
    }
}
