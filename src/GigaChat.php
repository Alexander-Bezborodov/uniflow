<?php

declare(strict_types=1);

namespace UniFlow;

final class GigaChat
{
    private Config $config;
    private Database $db;
    private Http $http;
    public function __construct(Config $config, Database $db, ?Http $http = null)
    {
        $this->config = $config;
        $this->db = $db;
        $this->http = $http ?? new Http();
    }
    public function available(): bool
    {
        return $this->config->enabled('GIGACHAT_ENABLED') && $this->config->get('GIGACHAT_AUTH_KEY') !== '';
    }
    private function cacheKey(): string
    {
        return 'gigachat_token_' . hash('sha256', $this->config->get('GIGACHAT_AUTH_KEY') . $this->config->get('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'));
    }
    private function token(bool $refresh = false): string
    {
        $key = $this->cacheKey();
        $row = $this->db->one('SELECT value FROM cache WHERE key=?', [$key]);
        $cached = $row ? json_decode($row['value'], true) : null;
        if (!$refresh && isset($cached['access_token'], $cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
        $scope = $this->config->get('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS');
        if (!in_array($scope, ['GIGACHAT_API_PERS','GIGACHAT_API_B2B','GIGACHAT_API_CORP'], true)) {
            throw new \RuntimeException('invalid_scope');
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        $uuid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
        $auth = $this->config->get('GIGACHAT_AUTH_KEY');
        if (preg_match('/[\r\n]/', $auth)) {
            throw new \RuntimeException('invalid_key');
        }
        $r = $this->http->post('https://ngw.devices.sberbank.ru:9443/api/v2/oauth', ['Content-Type: application/x-www-form-urlencoded','Accept: application/json','RqUID: ' . $uuid,'Authorization: Basic ' . $auth], http_build_query(['scope' => $scope]), $this->config->get('GIGACHAT_CA_BUNDLE'));
        $d = $r['data'];
        if ($r['status'] !== 200 || !isset($d['access_token'], $d['expires_at']) || !is_string($d['access_token'])) {
            throw new \RuntimeException('oauth_error');
        }

        $expiry = (float)$d['expires_at'];
        if ($expiry > 100000000000) {
            $expiry /= 1000;
        }
        if ($expiry <= time() + 60 || preg_match('/[\r\n]/', $d['access_token'])) {
            throw new \RuntimeException('invalid_token');
        }
        $d = ['access_token' => $d['access_token'],'expires_at' => (int)$expiry];
        $this->db->run('INSERT INTO cache(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,json_encode($d, JSON_THROW_ON_ERROR)]);
        return $d['access_token'];
    }
    public function complete(string $system, string $input): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('ai_disabled');
        }
        $base = rtrim($this->config->get('GIGACHAT_BASE_URL', 'https://api.giga.chat/v1'), '/');
        if (!in_array($base, ['https://api.giga.chat/v1','https://gigachat.devices.sberbank.ru/api/v1'], true)) {
            throw new \RuntimeException('invalid_api_url');
        }
        for ($i = 0; $i < 2; $i++) {
            $r = $this->http->post($base . '/chat/completions', ['Content-Type: application/json','Accept: application/json','Authorization: Bearer ' . $this->token($i === 1)], json_encode(['model' => $this->config->get('GIGACHAT_MODEL', 'GigaChat'),'messages' => [['role' => 'system','content' => $system],['role' => 'user','content' => $input]],'temperature' => 0.1,'max_tokens' => 2000,'stream' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->config->get('GIGACHAT_CA_BUNDLE'));
            if ($r['status'] === 401 && $i === 0) {
                continue;
            }
            if ($r['status'] !== 200) {
                throw new \RuntimeException('completion_error');
            }
            $text = $r['data']['choices'][0]['message']['content'] ?? null;
            if (!is_string($text) || strlen($text) > 30000) {
                throw new \RuntimeException('invalid_completion');
            }
            $text = preg_replace('~^```(?:json)?\s*|\s*```$~', '', trim($text));
            $json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($json)) {
                throw new \RuntimeException('invalid_completion');
            } return $json;
        }
        throw new \RuntimeException('authorization_failed');
    }
}
