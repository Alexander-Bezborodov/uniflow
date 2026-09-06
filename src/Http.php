<?php

declare(strict_types=1);

namespace UniFlow;

class Http
{
    public function post(string $url, array $headers, string $body, string $ca = ''): array
    {
        $ch = curl_init($url);
        $response = '';
        $options = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($ca !== '') {
            $options[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        unset($ch);
        if ($ok === false) {
            throw new \RuntimeException('transport_error');
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('invalid_response');
        }
        return ['status' => $status, 'data' => $data];
    }
}
