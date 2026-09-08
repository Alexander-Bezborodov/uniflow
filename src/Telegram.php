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

        $telegramUrl =
            'https://api.telegram.org/bot' .
            $this->config->get('BOT_TOKEN') .
            '/' .
            $method;
        $relayPayload = [
            'url' => $telegramUrl,
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $data,
            'timeout' => 30,
        ];
        $response = $this->http->post(
            $this->config->get('TELEGRAM_RELAY_URL'),
            $this->relayHeaders(),
            json_encode($relayPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            $this->relayCurlOptions(),
        );

        if (
            $response['status'] < 200 ||
            $response['status'] >= 300 ||
            !isset($response['data']['status'], $response['data']['body']) ||
            !is_string($response['data']['body'])
        ) {
            throw new \RuntimeException('invalid_relay_response');
        }

        $body = $response['data']['body'];
        $encoding = $response['data']['body_encoding'] ?? 'utf-8';
        if ($encoding === 'base64') {
            $decoded = base64_decode($body, true);
            if ($decoded === false) {
                throw new \RuntimeException('invalid_relay_response');
            }
            $body = $decoded;
        } elseif ($encoding !== 'utf-8') {
            throw new \RuntimeException('invalid_relay_response');
        }

        $telegramResponse = json_decode($body, true);
        if (!is_array($telegramResponse)) {
            throw new \RuntimeException('invalid_telegram_response');
        }

        return $telegramResponse;
    }

    public function createIncomingWebhookUrl(string $targetUrl): string
    {
        $response = $this->http->post(
            $this->config->get(
                'TELEGRAM_RELAY_WEBHOOK_URL',
            ),
            $this->relayHeaders(),
            json_encode(
                ['url' => $targetUrl],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            '',
            $this->relayCurlOptions(),
        );
        $webhookUrl = $response['data']['webhook_url'] ?? null;
        $returnedTarget = $response['data']['target_url'] ?? null;
        $parts = is_string($webhookUrl) ? parse_url($webhookUrl) : false;
        if (
            $response['status'] < 200 ||
            $response['status'] >= 300 ||
            $returnedTarget !== $targetUrl ||
            !is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== parse_url($this->config->get('TELEGRAM_RELAY_WEBHOOK_URL'), PHP_URL_HOST) ||
            strpos($parts['path'] ?? '', '/webhook/') !== 0
        ) {
            throw new \RuntimeException('invalid_incoming_relay_response');
        }

        return $webhookUrl;
    }

    private function relayHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->config->get('TELEGRAM_RELAY_TOKEN'),
            'Content-Type: application/json',
        ];
    }

    private function relayCurlOptions(): array
    {
        return [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_PINNEDPUBLICKEY => $this->config->get('TELEGRAM_RELAY_PIN'),
        ];
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
