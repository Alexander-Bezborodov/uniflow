<?php

declare(strict_types=1);

namespace UniFlow;

final class Telegram
{
    private Config $config;
    private Http $http;

    public function __construct(Config $config, ?Http $http = null)
    {
        $this->config = $config;
        $this->http = $http ?? new Http();
    }

    public function request(string $method, array $data): array
    {
        if (!$this->config->enabled('CUSTOM_EMOJI_ENABLED', true)) {
            $data = $this->plain($data);
        }
        $r = $this->http->post(
            'https://api.telegram.org/bot' . $this->config->get('BOT_TOKEN') . '/' . $method,
            ['Content-Type: application/json'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        return $r['data'];
    }

    private function plain(array $data): array
    {
        unset($data['icon_custom_emoji_id']);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->plain($v);
            } elseif (is_string($v) && in_array($k, ['text', 'caption'], true)) {
                $data[$k] = preg_replace('~<tg-emoji\b[^>]*>(.*?)</tg-emoji>~us', '$1', $v);
            }
        }
        return $data;
    }
}
