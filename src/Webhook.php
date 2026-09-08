<?php

declare(strict_types=1);

namespace UniFlow;

final class Webhook
{
    public static function handle(
        Database $db,
        Worker $worker,
        string $secret,
        string $method,
        string $supplied,
        string $body
    ): int {
        $status = self::accept($db, $secret, $method, $supplied, $body);
        if ($status !== 200) {
            return $status;
        }

        for ($i = 0; $i < 10; $i++) {
            if (!$worker->update()) {
                break;
            }
        }
        for ($i = 0; $i < 30; $i++) {
            if (!$worker->deliver()) {
                break;
            }
        }

        return 200;
    }

    public static function accept(
        Database $db,
        string $secret,
        string $method,
        string $supplied,
        string $body
    ): int {
        if ($method !== 'POST') {
            return 405;
        }
        if ($secret === '' || !hash_equals($secret, $supplied)) {
            return 403;
        }
        if (strlen($body) > 1048576) {
            return 413;
        }
        try {
            $u = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 400;
        }
        if (
            !is_array($u) ||
            !isset($u['update_id']) ||
            !is_int($u['update_id']) ||
            $u['update_id'] < 0
        ) {
            return 400;
        }
        $db->enqueue($u);
        return 200;
    }
}
