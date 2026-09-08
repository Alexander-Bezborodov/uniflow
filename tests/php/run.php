<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
use UniFlow\{Config,Database,Dates,Emojis,Parser,Planner,Bot,Webhook,Worker,Telegram,Http,GigaChat};
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$passed = 0;
function check($actual, $expected, string $message): void
{
    global $passed;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' | actual=' . var_export($actual, true) . ' expected=' . var_export($expected, true));
    } $passed++;
}
function test(string $name, callable $fn): void
{
    $fn();
    echo "PASS $name\n";
}
function database(): Database
{
    $db = new Database(':memory:');
    $db->initialize();
    return $db;
}
function config(): Config
{
    return new Config(dirname(__DIR__, 2), [
        'TIMEZONE' => 'Asia/Yekaterinburg',
        'BOT_TOKEN' => '123:test',
        'TELEGRAM_RELAY_URL' => 'https://relay.example/relay.php',
        'TELEGRAM_RELAY_WEBHOOK_URL' => 'https://relay.example/webhook-url.php',
        'TELEGRAM_RELAY_TOKEN' => str_repeat('b', 64),
        'TELEGRAM_RELAY_PIN' => 'sha256//' . str_repeat('A', 43) . '=',
        'WELCOME_IMAGE_RELAY_URL' => 'https://relay.example/webhook/image',
        'WEBHOOK_SECRET' => str_repeat('a', 32),
    ]);
}
function msg(string $s, int $id = 1, int $user = 11): array
{
    return ['update_id' => $id,'message' => ['message_id' => $id,'chat' => ['id' => $user,'type' => 'private'],'from' => ['id' => $user,'first_name' => 'Tester','is_bot' => false],'text' => $s]];
}
function callback(string $s, int $id = 1, int $user = 11): array
{
    return ['update_id' => $id,'callback_query' => ['id' => 'q' . $id,'from' => ['id' => $user,'is_bot' => false],'data' => $s,'message' => ['message_id' => 1,'chat' => ['id' => $user,'type' => 'private']]]];
}
function handle(Database $db, Bot $bot, array $u, array $prepared = []): void
{
    $userId = $u['message']['from']['id'] ?? $u['callback_query']['from']['id'] ?? null;
    $text = $u['message']['text'] ?? '';
    if (
        is_int($userId) &&
        empty($u['_test_new_profile']) &&
        $text !== '/start' &&
        !$db->one('SELECT telegram_id FROM users WHERE telegram_id=?', [$userId])
    ) {
        $now = config()->now();
        $db->upsertUser([$userId, null, 'Tester', $now->getTimezone()->getName(), Dates::utc($now)]);
    }
    if (!$prepared && isset($u['message']['text']) && $u['message']['text'][0] !== '/' &&
        in_array($db->state($u['message']['from']['id'])['step'] ?? '', ['', 'ai_input'], true)) {
        $prepared = ['drafts' => Parser::local($u['message']['text'], config()->now()), 'source' => 'GigaChat'];
    }
    $db->transaction(function () use ($bot, $u, $prepared) {
        $bot->handle($u, $prepared);
    });
}
class FakeHttp extends Http
{
    public array $calls = [];
    public array $relayCalls = [];
    public array $incomingRelayCalls = [];
    public array $responses = [];
    public function post(
        string $url,
        array $headers,
        string $body,
        string $ca = '',
        array $curlOptions = []
    ): array {
        if (strpos($url, '/webhook-url.php') !== false) {
            $this->incomingRelayCalls[] = [$url, $headers, $body, $ca, $curlOptions];
            $payload = json_decode($body, true);
            return [
                'status' => 201,
                'data' => [
                    'webhook_url' => 'https://relay.example/webhook/signature/target',
                    'target_url' => $payload['url'],
                    'behavior' => 'forwarded',
                ],
            ];
        }
        if (strpos($url, '/relay.php') !== false) {
            $this->relayCalls[] = [$url, $headers, $body, $ca, $curlOptions];
            $relayPayload = json_decode($body, true);
            $this->calls[] = [
                $relayPayload['url'],
                $relayPayload['headers'],
                json_encode($relayPayload['body'], JSON_UNESCAPED_UNICODE),
                '',
            ];
            $targetResponse = array_shift($this->responses) ?? [
                'status' => 200,
                'data' => ['ok' => true, 'result' => ['message_id' => 1]],
            ];
            return [
                'status' => 200,
                'data' => [
                    'status' => $targetResponse['status'],
                    'headers' => [],
                    'content_type' => 'application/json',
                    'duration_ms' => 1,
                    'body' => json_encode($targetResponse['data'], JSON_UNESCAPED_UNICODE),
                    'body_encoding' => 'utf-8',
                ],
            ];
        }

        $this->calls[] = [$url, $headers, $body, $ca];
        return array_shift($this->responses) ?? [
            'status' => 200,
            'data' => ['ok' => true, 'result' => ['message_id' => 1]],
        ];
    }
}
$now = new DateTimeImmutable('2026-09-05T12:00:00+05:00');
test('dates, UTC, invalid input, DST and duration', function () use ($now) {
    check(Dates::parse('завтра в 18:00', $now), '2026-09-06T13:00:00.000000+00:00', 'local to UTC');
    check(Dates::parse('завтра в 12', $now), '2026-09-06T07:00:00.000000+00:00', 'hour without minutes');
    check(Dates::parse('сегодня', $now), '2026-09-05T18:59:00.000000+00:00', 'default time');
    check(Dates::parse('через минуту', $now), '2026-09-05T07:01:00.000000+00:00', 'relative minute');
    check(Dates::parse('сегодня через 5 минут', $now), '2026-09-05T07:05:00.000000+00:00', 'today relative minutes');
    check(Dates::parse('через час', $now), '2026-09-05T08:00:00.000000+00:00', 'relative hour');
    check(Dates::parse('31.02.2027', $now), null, 'invalid day');
    check(Dates::parse('31.04', $now), null, 'invalid month day');
    check(Dates::parse('завтра в 25:10', $now), null, 'invalid clock');
    check(Dates::parse('завтра мусор', $now), null, 'reject trailing text');
    check(Dates::parse('29.02', $now), '2028-02-29T18:59:00.000000+00:00', 'leap year');
    check(Dates::parse('в пятницу', $now), '2026-09-11T18:59:00.000000+00:00', 'weekday');
    check(Dates::parse('10 сентября', $now), '2026-09-10T18:59:00.000000+00:00', 'month');
    check(Dates::parse('через 2 недели', $now), '2026-09-19T18:59:00.000000+00:00', 'weeks');
    check(Dates::parse('29.03.2026 в 02:30', new DateTimeImmutable('2026-03-01', new DateTimeZone('Europe/Berlin'))), null, 'nonexistent time');
    check(Dates::parse('18:00 tomorrow', $now), '2026-09-06T13:00:00.000000+00:00', 'English time before relative date');
    check(Dates::parse('tomorrow 18:00', $now), '2026-09-06T13:00:00.000000+00:00', 'English relative date and time');
    check(Dates::parse('tomorrow at 18:00', $now), '2026-09-06T13:00:00.000000+00:00', 'English relative date with at');
    check(Dates::parse('tomorrow at 6 PM', $now), '2026-09-06T13:00:00.000000+00:00', 'English twelve-hour time');
    check(Dates::parse('next friday at 9:30 am', $now), '2026-09-11T04:30:00.000000+00:00', 'English weekday');
    check(Dates::parse('September 10 at 8 PM', $now), '2026-09-10T15:00:00.000000+00:00', 'English month first date');
    $englishTask = Parser::local('Send the algebra homework tomorrow at 6 PM', $now);
    check(count($englishTask), 1, 'English task date is found in full sentence');
    check($englishTask[0]['deadline'], '2026-09-06T13:00:00.000000+00:00', 'English task deadline is preserved');
    check(Dates::duration('1.5 часа'), 90, 'fraction');
    check(Dates::duration('1 час 30 минут'), 90, 'compound');
    check(Dates::duration('полтора часа'), 90, 'words');
    check(Dates::duration('1.5 hours'), 90, 'English fraction');
    check(Dates::duration('30 minutes'), 30, 'English minutes');
    check(Dates::duration('10081'), null, 'limit');
    check(Dates::duration('-5 минут'), null, 'negative');
});
test('custom emoji and safe HTML', function () {
    $s = Emojis::render('✅ <b>Готово</b> ⚙️');
    check(substr_count($s, '<tg-emoji '), 2, 'all emoji');
    check(Emojis::render($s), $s, 'idempotent');
    check(Emojis::button('✅ Готово', 'done'), ['text' => 'Готово','icon_custom_emoji_id' => '5357069174512303778','callback_data' => 'done'], 'button');
    check(Emojis::escape('<script>x</script>'), '&lt;script&gt;x&lt;/script&gt;', 'escape user text');
    check(Emojis::normalize("a\u{2014}b\u{2013}c"), 'a-b-c', 'dash');
});
test('Telegram requests use authenticated pinned relay', function () {
    $http = new FakeHttp();
    $telegram = new Telegram(config(), $http);
    $response = $telegram->request('sendMessage', ['chat_id' => 11, 'text' => 'Тест']);
    $relayCall = $http->relayCalls[0];
    $relayPayload = json_decode($relayCall[2], true);

    check($response['ok'], true, 'relay response unpacked');
    check($relayCall[0], 'https://relay.example/relay.php', 'relay URL');
    check(
        in_array('Authorization: Bearer ' . str_repeat('b', 64), $relayCall[1], true),
        true,
        'relay authorization',
    );
    check($relayPayload['url'], 'https://api.telegram.org/bot123:test/sendMessage', 'target URL');
    check($relayPayload['method'], 'POST', 'target method');
    check($relayPayload['body']['text'], 'Тест', 'target body');
    check($relayCall[4][CURLOPT_SSL_VERIFYPEER], false, 'relay peer verification option');
    check($relayCall[4][CURLOPT_SSL_VERIFYHOST], false, 'relay host verification option');
    check(
        $relayCall[4][CURLOPT_PINNEDPUBLICKEY],
        'sha256//' . str_repeat('A', 43) . '=',
        'relay public key pin',
    );
});
test('Telegram webhook URL uses signed incoming relay', function () {
    $http = new FakeHttp();
    $telegram = new Telegram(config(), $http);
    $target = 'https://bot.example/public/webhook.php';
    $url = $telegram->createIncomingWebhookUrl($target);
    $call = $http->incomingRelayCalls[0];
    $payload = json_decode($call[2], true);

    check($url, 'https://relay.example/webhook/signature/target', 'signed webhook URL');
    check($call[0], 'https://relay.example/webhook-url.php', 'incoming relay endpoint');
    check($payload['url'], $target, 'incoming relay target');
    check($call[4][CURLOPT_SSL_VERIFYPEER], false, 'incoming relay peer option');
    check($call[4][CURLOPT_SSL_VERIFYHOST], false, 'incoming relay host option');
    check(
        $call[4][CURLOPT_PINNEDPUBLICKEY],
        'sha256//' . str_repeat('A', 43) . '=',
        'incoming relay public key pin',
    );
});
test('schema migration preserves existing tasks and timestamps', function () {
    $db = new Database(':memory:');
    $db->pdo->exec("CREATE TABLE users(telegram_id INTEGER PRIMARY KEY,username TEXT,full_name TEXT,timezone TEXT NOT NULL,created_at DATETIME NOT NULL,reminders_disabled INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(telegram_id),title TEXT NOT NULL,subject TEXT,deadline DATETIME NOT NULL,estimated_minutes INTEGER NOT NULL DEFAULT 60,importance INTEGER NOT NULL DEFAULT 2,status TEXT NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,completed_at DATETIME,reminder_24h_sent INTEGER NOT NULL DEFAULT 0,reminder_3h_sent INTEGER NOT NULL DEFAULT 0,is_demo INTEGER NOT NULL DEFAULT 0);
    INSERT INTO users VALUES(11,NULL,NULL,'Europe/Moscow','2026-01-01T00:00:00.000000+00:00',0);
    INSERT INTO tasks(user_id,title,deadline,created_at) VALUES(11,'Old task','2026-09-05T19:30:00.000000+00:00','2026-01-01T00:00:00.000000+00:00');");
    $db->pdo->exec('PRAGMA user_version=2');
    $db->initialize();
    $db->initialize();
    check($db->tasks(11)[0]['deadline'], '2026-09-05T19:30:00.000000+00:00', 'deadline preserved');
    check((int)$db->pdo->query('PRAGMA user_version')->fetchColumn(), 5, 'version');
    check((int)$db->tasks(11)[0]['revision'], 0, 'revision');
    check((int)$db->tasks(11)[0]['importance_visible'], 0, 'priority hidden for old tasks');
    check((int)$db->tasks(11)[0]['duration_visible'], 1, 'duration shown for old tasks');
    check((int)$db->tasks(11)[0]['overdue_sent'], 0, 'overdue notification not sent');
    check($db->one('SELECT language FROM users WHERE telegram_id=11')['language'], 'ru', 'legacy language');
    check($db->one('SELECT timezone FROM users WHERE telegram_id=11')['timezone'], 'Europe/Moscow', 'legacy timezone retained');
});
test('webhook authentication, duplicate update and durable processing', function () {
    $db = database();
    $c = config();
    $secret = $c->get('WEBHOOK_SECRET');
    $body = json_encode(msg('/start', 99));
    check(Webhook::accept($db, $secret, 'GET', $secret, $body), 405, 'method');
    check(Webhook::accept($db, $secret, 'POST', 'wrong', $body), 403, 'secret');
    check(Webhook::accept($db, $secret, 'POST', $secret, '{'), 400, 'json');
    check(Webhook::accept($db, $secret, 'POST', $secret, str_repeat('x', 1048577)), 413, 'size');
    check(Webhook::accept($db, $secret, 'POST', $secret, $body), 200, 'accept');
    check(Webhook::accept($db, $secret, 'POST', $secret, $body), 200, 'retry ack');
    check((int)$db->pdo->query('SELECT count(*) FROM updates')->fetchColumn(), 1, 'dedup');
    $http = new FakeHttp();
    $worker = new Worker($db, $c, new Telegram($c, $http));
    check($worker->update(), true, 'process');
    check($worker->update(), false, 'once');
    check($worker->deliver(), true, 'outbox send');
    check($worker->deliver(), false, 'delivered once');
    check(count($http->calls), 1, 'one send');
});
test('webhook processes update and sends reply synchronously', function () {
    $db = database();
    $config = config();
    $http = new FakeHttp();
    $worker = new Worker($db, $config, new Telegram($config, $http));
    $body = json_encode(msg('/start', 100));
    $status = Webhook::handle(
        $db,
        $worker,
        $config->get('WEBHOOK_SECRET'),
        'POST',
        $config->get('WEBHOOK_SECRET'),
        $body,
    );

    check($status, 200, 'synchronous webhook status');
    check(count($http->calls), 1, 'synchronous reply sent');
    check(
        (int) $db->one('SELECT processed_at FROM updates WHERE id=100')['processed_at'] > 0,
        true,
        'synchronous update processed',
    );
});
test('simple add, persistence, ownership, edit, stale callback and completion', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    check($db->state(11)['step'], 'ai_input', 'GigaChat selected automatically');
    handle($db, $bot, msg('Сделать лабораторную по Java завтра в 18:00 1.5 часа', 2));
    check($db->state(11)['step'], 'priority', 'asks priority');
    handle($db, $bot, msg('🟠 Высокий', 3));
    $tasks = $db->tasks(11);
    check(count($tasks), 1, 'task saved');
    $id = (int)$tasks[0]['id'];
    check((int)$tasks[0]['estimated_minutes'], 90, 'duration');
    check($db->state(11), [], 'state consumed');
    handle($db, $bot, callback('task:done:' . $id, 1, 22));
    check(count($db->tasks(11)), 1, 'ownership');
    handle($db, $bot, callback('task:detail:' . $id));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    $detailRows = $payload['reply_markup']['inline_keyboard'];
    check($detailRows[count($detailRows) - 1][0]['text'], 'Назад к списку', 'task card has back button');
    check($detailRows[count($detailRows) - 1][0]['callback_data'], 'page:tasks:0', 'task back opens task list');
    handle($db, $bot, callback('task:edit:' . $id));
    $s = $db->state(11);
    handle($db, $bot, callback('editfield:' . $s['token'] . ':title'));
    handle($db, $bot, msg('Новое название'));
    $s = $db->state(11);
    $confirm = 'editconfirm:' . $s['token'] . ':save';
    handle($db, $bot, callback($confirm));
    handle($db, $bot, callback($confirm));
    check($db->tasks(11)[0]['title'], 'Новое название', 'edit');
    check((int)$db->tasks(11)[0]['revision'], 1, 'stale edit ignored');
    check($db->one('SELECT method FROM outbox ORDER BY id DESC LIMIT 1')['method'], 'editMessageReplyMarkup', 'stale button is removed silently');
    handle($db, $bot, callback('task:delete:' . $id));
    $s = $db->state(11);
    handle($db, $bot, callback('delete:' . $s['token'] . ':no'));
    check(count($db->tasks(11)), 1, 'cancel deletion');
    handle($db, $bot, callback('task:done:' . $id));
    check(count($db->tasks(11)), 0, 'completed');
    check(count($db->tasks(11, false)), 1, 'history');
});
test('single task saves immediately and multiple tasks require confirmation', function () use ($now) {
    $parsed = Parser::local('Лаба по Java до пятницы 2 часа', $now);
    check(count($parsed), 1, 'parse');
    check($parsed[0]['estimated_minutes'], 120, 'parse duration');
    check(Parser::local('привет', $now), [], 'not task');
    check(Parser::local('Сдать отчёт Т', $now)[0]['title'], 'Сдать отчёт Т', 'Cyrillic boundary remains valid');
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    handle($db, $bot, msg('Сделать лабораторную'));
    check($db->state(11)['field'], 'deadline', 'ask date');
    handle($db, $bot, msg('завтра'));
    check($db->state(11)['step'], 'duration', 'duration follows date');
    $s = $db->state(11);
    handle($db, $bot, callback('duration:' . $s['token'] . ':60', 2));
    check($db->state(11)['step'], 'priority', 'priority follows duration');
    handle($db, $bot, msg('Пропустить', 2));
    check(count($db->tasks(11)), 1, 'single task saved immediately');
    check((int) $db->tasks(11)[0]['estimated_minutes'], 60, 'default duration');

    handle($db, $bot, msg('/add'));
    handle(
        $db,
        $bot,
        msg('Сделать презентацию завтра и написать отчёт послезавтра', 3),
    );
    $s = $db->state(11);
    handle($db, $bot, callback('duration:' . $s['token'] . ':10', 40));
    $s = $db->state(11);
    handle($db, $bot, callback('duration:' . $s['token'] . ':skip', 41));
    handle($db, $bot, msg('Низкий', 4));
    handle($db, $bot, msg('Пропустить', 5));
    $s = $db->state(11);
    check($s['step'], 'review', 'multiple tasks previewed');
    $token = $s['token'];
    handle($db, $bot, callback('review:' . $token . ':add'));
    handle($db, $bot, callback('review:' . $token . ':add'));
    check(count($db->tasks(11)), 3, 'multiple tasks confirmed only once');
});
test('minute deadlines and Today work while entering a deadline', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    handle($db, $bot, msg('Через минуту написать привет', 2));
    $state = $db->state(11);
    check($state['step'], 'duration', 'relative minute deadline recognized');
    check(
        strtotime($state['drafts'][0]['deadline']) - $c->now()->getTimestamp(),
        60,
        'relative minute stored',
    );

    handle($db, $bot, msg('/add', 3));
    handle($db, $bot, msg('Написать привет', 4));
    check($db->state(11)['field'], 'deadline', 'deadline requested');
    handle($db, $bot, msg('Сегодня', 5));
    $state = $db->state(11);
    check($state['step'], 'duration', 'Today treated as deadline input');
    check(
        (new DateTimeImmutable($state['drafts'][0]['deadline']))
            ->setTimezone($c->now()->getTimezone())
            ->format('Y-m-d H:i'),
        $c->now()->format('Y-m-d') . ' 23:59',
        'Today uses end of day',
    );
});
test('task not found response uses a random example', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    handle($db, $bot, msg('привет', 2));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos($payload['text'], 'Не нашёл задач. Попробуй «') === 0, true, 'random retry example');
    check(strpos($payload['text'], 'Лаба по Java до пятницы 2 часа') === false, true, 'fixed retry example removed');
});
test('planning, simplified menu, settings and automatic AI route', function () use ($now) {
    $t = ['id' => 1,'deadline' => Dates::utc($now->modify('+2 hours')),'estimated_minutes' => 240,'importance' => 3];
    $card = Planner::card(['title' => 'Без предмета'] + $t, $now);
    check(strpos($card, 'Предмет:') === false, true, 'missing subject label hidden');
    check(strpos($card, "</b>\n\n📅") !== false, true, 'missing subject leaves blank line');
    check(Planner::priority($t, $now), 4, 'urgent');
    check(Planner::minutes(240, $t['deadline'], $now), 240, 'uncapped urgent');
    check(Planner::minutes(100, Dates::utc($now->modify('+10 days')), $now), 10, 'spread');
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->create(11, ['title' => 'Лаба','subject' => 'Java','deadline' => Dates::utc($now->modify('+2 days')),'estimated_minutes' => 90,'importance' => 2], $now);
    foreach (['/today','/tasks','/week','/stats','/help','/settings'] as $cmd) {
        handle($db, $bot, msg($cmd));
    }
    $s = $db->state(11);
    handle($db, $bot, callback('settings:' . $s['token'] . ':reminders'));
    check((int)$db->one('SELECT reminders_disabled FROM users WHERE telegram_id=11')['reminders_disabled'], 1, 'settings');
    handle($db, $bot, msg('/explain'));
    $s = $db->state(11);
    handle($db, $bot, callback('ai:' . $s['token'] . ':provider'));
    check($db->state(11), [], 'explain finished');
    handle($db, $bot, msg('/ai'));
    handle($db, $bot, msg('Лаба завтра 2 часа'));
    handle($db, $bot, msg('Пропустить', 20));
    check($db->state(11), [], 'single AI task saved');
    $allMessages = '';
    foreach ($db->run("SELECT payload FROM outbox WHERE method='sendMessage'")->fetchAll() as $row) {
        $p = json_decode($row['payload'], true);
        $allMessages .= "\n" . ($p['text'] ?? '');
        check(mb_strlen(strip_tags($p['text'])) <= 4096, true, 'Telegram length');
        foreach ($p['reply_markup']['inline_keyboard'] ?? [] as $buttons) {
            foreach ($buttons as $b) {
                check(isset($b['text'], $b['callback_data']), true, 'button shape');
                check(strlen($b['callback_data']) <= 64, true, 'callback limit');
            }
        }
    }
    check(strpos($allMessages, 'Время бота'), false, 'bot time removed');
    check(stripos($allMessages, 'демо'), false, 'demo removed');
    check(strpos($allMessages, 'Важность'), false, 'importance removed');
    check(strpos(json_encode(Emojis::menu(), JSON_UNESCAPED_UNICODE), 'Разобрать задание'), false, 'parse button removed');
});
test('welcome image, concise add flow, dynamic explain, help and message editing', function () {
    $db = database();
    $config = config();
    $bot = new Bot($db, $config);

    handle($db, $bot, msg('/start'));
    $row = $db->one('SELECT * FROM outbox ORDER BY id DESC LIMIT 1');
    $payload = json_decode($row['payload'], true);
    check($row['method'], 'sendRichMessage', 'welcome uses a rich message');
    check(strpos($payload['rich_message']['markdown'], '![](https://relay.example/webhook/image)') !== false, true, 'welcome image URL');
    check(strpos($payload['rich_message']['markdown'], '# Привет! Я UniFlow') !== false, true, 'welcome uses a large heading');

    handle($db, $bot, msg('/add', 2));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos($payload['text'], "символов.\n\n<i>Например: ") !== false, true, 'random example shown in italics');
    check(strpos($payload['text'], ' (обработает GigaChat)</i>') !== false, true, 'AI hint included in italics');
    check($payload['reply_markup']['keyboard'][0][0]['text'], 'Отмена', 'cancel keyboard');
    preg_match('~<i>Например: (.*?) \(обработает GigaChat\)</i>~u', $payload['text'], $match);
    check($payload['reply_markup']['keyboard'][1][0]['text'], $match[1], 'example keyboard button');
    $examples = [$match[1]];
    for ($i = 0; $i < 5; $i++) {
        handle($db, $bot, msg('/add', 20 + $i));
        $next = json_decode(
            $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
            true,
        );
        preg_match('~<i>Например: (.*?) \(обработает GigaChat\)</i>~u', $next['text'], $match);
        check($next['reply_markup']['keyboard'][1][0]['text'], $match[1], 'rotated example keyboard button');
        $examples[] = $match[1];
    }
    check(count(array_unique($examples)), 6, 'recent task examples do not repeat');

    handle($db, $bot, msg('Лаба завтра 30 минут', 3));
    $priorityPayload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos($priorityPayload['text'], 'Какая важность у задачи?') !== false, true, 'priority prompt');
    check(strpos($priorityPayload['text'], 'GigaChat') === false, true, 'AI status hidden from priority');
    $priorityButtons = $priorityPayload['reply_markup']['inline_keyboard'][0];
    check(array_column($priorityButtons, 'text'), ['Низкий', 'Средний', 'Высокий'], 'priority choices in one row');
    check(array_column($priorityPayload['reply_markup']['inline_keyboard'][1], 'text'), ['Пропустить', 'Отмена'], 'skip and cancel share one row');
    foreach ($priorityButtons as $button) {
        check(isset($button['icon_custom_emoji_id']), false, 'priority choice without emoji');
    }
    $priorityState = $db->state(11);
    handle($db, $bot, callback('priority:' . $priorityState['token'] . ':skip', 30));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos($payload['text'], '30 мин на задачу') !== false, true, 'duration explained');
    check(strpos($payload['text'], 'Средний') === false, true, 'skipped priority hidden');
    check(strpos($payload['text'], '<code>Мы напомним вам об этом</code>') !== false, true, 'reminder note');
    check($payload['reply_markup']['keyboard'][0][0]['text'], 'Сегодня', 'main menu restored after adding task');
    check(strpos(json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE), 'Объяснить план'), false, 'explain hidden for one task');
    check(
        (int) $db->pdo->query("SELECT count(*) FROM outbox WHERE method='deleteMessage'")->fetchColumn(),
        1,
        'priority answer removed',
    );

    handle($db, $bot, msg('/tasks', 32));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos($payload['text'], '1/1'), false, 'single page counter hidden');
    check(strpos($payload['text'], 'Всего на сегодня'), false, 'single task total hidden');
    check(strpos($payload['text'], 'Сегодня:'), false, 'single task daily duration hidden');
    check($payload['reply_markup']['inline_keyboard'][0][0]['callback_data'], 'task:detail:1', 'single task detail button shown');

    handle($db, $bot, msg('/add', 4));
    handle($db, $bot, msg('Отчёт послезавтра 20 минут', 5));
    $priorityState = $db->state(11);
    handle($db, $bot, callback('priority:' . $priorityState['token'] . ':high', 31));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(strpos(json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE), 'Объяснить план') !== false, true, 'main menu restored with explain for multiple tasks');
    check(strpos($payload['text'], 'Высокий') !== false, true, 'selected priority shown');

    handle($db, $bot, msg('/tasks', 33));
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(
        $payload['reply_markup']['inline_keyboard'][0][0]['icon_custom_emoji_id'],
        '5258215635996908355',
        'multiple task button uses brush icon',
    );
    check(strpos($payload['text'], 'Сегодня:'), false, 'daily duration label removed');

    handle($db, $bot, msg('/settings', 6));
    $state = $db->state(11);
    handle($db, $bot, callback('settings:' . $state['token'] . ':reminders', 7));
    check(
        $db->one('SELECT method FROM outbox ORDER BY id DESC LIMIT 1')['method'],
        'editMessageText',
        'settings edits message',
    );

    handle($db, $bot, msg('/help', 8));
    $state = $db->state(11);
    $payload = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    );
    check(isset($payload['reply_markup']['inline_keyboard']), true, 'help uses inline keyboard');
    handle($db, $bot, callback('help:' . $state['token'] . ':add', 9));
    check(
        $db->one('SELECT method FROM outbox ORDER BY id DESC LIMIT 1')['method'],
        'editMessageText',
        'help edits message',
    );

    handle($db, $bot, msg('/stats', 10));
    $statisticsRow = $db->one('SELECT method,payload FROM outbox ORDER BY id DESC LIMIT 1');
    $payload = json_decode($statisticsRow['payload'], true);
    check($statisticsRow['method'], 'sendRichMessage', 'statistics uses Telegram rich message');
    check(isset($payload['rich_message']['markdown']), true, 'statistics uses native rich message');
    check(strpos($payload['rich_message']['markdown'], '| Показатель | Значение |') !== false, true, 'statistics uses native markdown table');
});
test('today and all tasks contain different dates', function () {
    $db = database();
    $config = config();
    $bot = new Bot($db, $config);
    handle($db, $bot, msg('/start'));
    $now = $config->now();
    $db->create(11, [
        'title' => 'Сегодняшняя задача',
        'deadline' => Dates::utc($now->setTime(23, 0)),
        'estimated_minutes' => 30,
        'importance' => 2,
    ], $now);
    $db->create(11, [
        'title' => 'Завтрашняя задача',
        'deadline' => Dates::utc($now->modify('+1 day')->setTime(12, 0)),
        'estimated_minutes' => 30,
        'importance' => 2,
    ], $now);

    handle($db, $bot, msg('/today', 40));
    $today = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    )['text'];
    check(strpos($today, 'Сегодняшняя задача') !== false, true, 'today task shown');
    check(strpos($today, 'Завтрашняя задача'), false, 'tomorrow task hidden today');

    handle($db, $bot, msg('/tasks', 41));
    $all = json_decode(
        $db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'],
        true,
    )['text'];
    check(strpos($all, 'Сегодняшняя задача') !== false, true, 'today task shown in all');
    check(strpos($all, 'Завтрашняя задача') !== false, true, 'tomorrow task shown in all');
});
test('welcome rich message is delivered through Telegram', function () {
    $db = database();
    $config = config();
    $bot = new Bot($db, $config);
    handle($db, $bot, msg('/start'));
    $http = new FakeHttp();
    $http->responses = [['status' => 200, 'data' => ['ok' => true, 'result' => ['message_id' => 1]]]];
    $worker = new Worker($db, $config, new Telegram($config, $http));
    check($worker->deliver(), true, 'welcome delivered');
    check(substr($http->calls[0][0], -16), '/sendRichMessage', 'rich message endpoint used');
});
test('reminder uniqueness, revision invalidation and retry after', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    $now = $c->now();
    $db->create(11, ['title' => 'Deadline','subject' => null,'deadline' => Dates::utc($now->modify('+5 hours')),'estimated_minutes' => 60,'importance' => 2,'importance_visible' => 1], $now);
    $http = new FakeHttp();
    $worker = new Worker($db, $c, new Telegram($c, $http));
    $worker->reminders();
    $worker->reminders();
    check((int)$db->pdo->query('SELECT count(*) FROM outbox')->fetchColumn(), 1, 'one reminder');
    $db->run('UPDATE tasks SET revision=revision+1,deadline=?', [Dates::utc($now->modify('+2 hours'))]);
    $worker->deliver();
    check(count($http->calls), 0, 'stale reminder skipped');
    $worker->reminders();
    $http->responses = [['status' => 429,'data' => ['ok' => false,'error_code' => 429,'parameters' => ['retry_after' => 5]]]];
    $worker->deliver();
    check((int)$db->tasks(11)[0]['reminder_3h_sent'], 0, 'retry not marked sent');
    check($worker->deliver(), false, 'rate pause');
    $db->run("DELETE FROM cache WHERE key='telegram_retry_at'");
    $db->run('UPDATE outbox SET available_at=0');
    $worker->deliver();
    check((int)$db->tasks(11)[0]['reminder_3h_sent'], 1, 'sent flag');
    $worker->reminders();
    check((int)$db->pdo->query('SELECT count(*) FROM outbox WHERE sent_at IS NULL')->fetchColumn(), 0, 'no duplicates');
});
test('reminders follow priority windows and always include two hours', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    $now = $c->now();
    $db->create(11, ['title'=>'High','deadline'=>Dates::utc($now->modify('+10 hours')),'estimated_minutes'=>30,'importance'=>3,'importance_visible'=>1], $now);
    $db->create(11, ['title'=>'Medium','deadline'=>Dates::utc($now->modify('+5 hours')),'estimated_minutes'=>30,'importance'=>2,'importance_visible'=>1], $now);
    $db->create(11, ['title'=>'Low','deadline'=>Dates::utc($now->modify('+90 minutes')),'estimated_minutes'=>30,'importance'=>1,'importance_visible'=>1], $now);
    $worker = new Worker($db, $c, new Telegram($c, new FakeHttp()));
    $worker->reminders();
    $hours = array_map('intval', array_column($db->run('SELECT reminder_hours FROM outbox ORDER BY reminder_hours')->fetchAll(), 'reminder_hours'));
    check($hours, [2, 6, 12], 'priority reminder windows');
});
test('near deadline reminder shows exact remaining minutes', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    $now = $c->now();
    $db->create(11, [
        'title' => 'Скоро начнётся',
        'deadline' => Dates::utc($now->modify('+10 minutes')),
        'estimated_minutes' => 10,
        'importance' => 1,
    ], $now);
    $worker = new Worker($db, $c, new Telegram($c, new FakeHttp()));
    $worker->reminders();
    $payload = json_decode($db->one('SELECT payload FROM outbox')['payload'], true);
    check(strpos($payload['text'], 'Осталось 10 минут.') !== false, true, 'exact minutes shown');
    check(strpos($payload['text'], 'не более 2 ч.') === false, true, 'two hour wording omitted');
});
test('overdue reminder is unique and offers reschedule and completion', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    $now = $c->now();
    $id = $db->create(11, [
        'title' => 'Просроченная задача',
        'deadline' => Dates::utc($now->modify('+1 hour')),
        'estimated_minutes' => 30,
        'importance' => 2,
    ], $now);
    $db->run('UPDATE tasks SET deadline=? WHERE id=?', [
        Dates::utc($now->modify('-10 minutes')),
        $id,
    ]);
    $worker = new Worker($db, $c, new Telegram($c, new FakeHttp()));
    $worker->reminders();
    $worker->reminders();
    $rows = $db->run('SELECT payload,reminder_hours FROM outbox')->fetchAll();
    check(count($rows), 1, 'one overdue reminder');
    check((int) $rows[0]['reminder_hours'], 0, 'overdue reminder marker');
    $payload = json_decode($rows[0]['payload'], true);
    check(strpos($payload['text'], 'Вы просрочили задачу') !== false, true, 'overdue text');
    check($payload['reply_markup']['inline_keyboard'][0][0]['callback_data'], 'task:reschedule:' . $id, 'reschedule button');
    check($payload['reply_markup']['inline_keyboard'][0][1]['callback_data'], 'task:done:' . $id, 'complete button');

    handle($db, $bot, callback('task:reschedule:' . $id, 20));
    $state = $db->state(11);
    check($state['step'], 'field', 'reschedule asks for field');
    check($state['field'], 'deadline', 'reschedule asks for deadline');
    check($state['mode'], 'saved_edit', 'reschedule uses saved edit');
    $last = $db->one('SELECT method FROM outbox ORDER BY id DESC LIMIT 1');
    check($last['method'], 'sendMessage', 'reschedule sends deadline prompt');
});
test('settings can delete all user data after confirmation', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->create(11, ['title'=>'Delete me','deadline'=>Dates::utc($c->now()->modify('+1 day')),'estimated_minutes'=>30,'importance'=>2], $c->now());
    handle($db, $bot, msg('/settings', 2));
    $state = $db->state(11);
    handle($db, $bot, callback('settings:' . $state['token'] . ':delete_data', 3));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], 'Удалить все ваши данные?') !== false, true, 'delete data asks for confirmation');
    handle($db, $bot, callback('settings:' . $state['token'] . ':delete_data_yes', 4));
    check(count($db->tasks(11, false)), 0, 'all user tasks deleted');
    check($db->one('SELECT * FROM users WHERE telegram_id=11'), null, 'user profile deleted');
    check($db->one('SELECT * FROM sessions WHERE user_id=11'), null, 'user session deleted');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check($payload['text'], 'Ваши данные удалены.', 'data deletion confirmed');
    check($payload['reply_markup']['remove_keyboard'], true, 'keyboard removed after profile deletion');
    $restartUpdate = msg('Проверить старую задачу', 5);
    $restartUpdate['_test_new_profile'] = true;
    handle($db, $bot, $restartUpdate);
    check($db->state(11)['step'], 'onboarding', 'message after deletion starts onboarding');
    $row = $db->one('SELECT method,payload FROM outbox ORDER BY id DESC LIMIT 1');
    check($row['method'], 'sendRichMessage', 'message after deletion sends welcome');
    check(strpos(json_decode($row['payload'], true)['rich_message']['markdown'], '# Привет! Я UniFlow') !== false, true, 'message after deletion follows start flow');
});
test('username is neither stored in profile nor queued updates', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    $update = msg('/start');
    $update['message']['from']['username'] = 'private_username';
    handle($db, $bot, $update);
    check($db->one('SELECT username FROM users WHERE telegram_id=11')['username'], null, 'profile username is not stored');
    $db->enqueue($update);
    $payload = $db->one('SELECT payload FROM updates WHERE id=1')['payload'];
    check(strpos($payload, 'private_username') === false, true, 'queued update does not contain username');
});
test('settings close removes settings and user request messages', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    handle($db, $bot, msg('/settings', 22));
    $state = $db->state(11);
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check($payload['reply_markup']['inline_keyboard'][4][0]['text'], 'Закрыть', 'settings have close button');
    handle($db, $bot, callback('settings:' . $state['token'] . ':close', 23));
    $deleted = array_map(function (array $row): int {
        return (int) json_decode($row['payload'], true)['message_id'];
    }, $db->run("SELECT payload FROM outbox WHERE method='deleteMessage' ORDER BY id")->fetchAll());
    sort($deleted);
    check($deleted, [1, 22], 'close removes settings and request messages');
    check($db->state(11), [], 'close clears settings clears');
});
test('OAuth cache, 401 refresh, scope, TLS, validated AI and fallback', function () use ($now) {
    $db = database();
    $c = new Config(dirname(__DIR__, 2), ['GIGACHAT_ENABLED' => 'true','GIGACHAT_AUTH_KEY' => 'base64key']);
    $http = new FakeHttp();
    $token = ['status' => 200,'data' => ['access_token' => 'token','expires_at' => (time() + 1800) * 1000]];
    $reply = ['status' => 200,'data' => ['choices' => [['message' => ['content' => '{"tasks":[{"title":"Лаба","subject":null}]}']]]]];
    $http->responses = [$token,$reply,$reply,['status' => 401,'data' => ['message' => 'expired']],$token,$reply];
    $ai = new GigaChat($c, $db, $http);
    $drafts = Parser::ai($ai, 'Лаба завтра 2 часа', $now);
    check($drafts[0]['estimated_minutes'], 120, 'grounding');
    Parser::ai($ai, 'Лаба завтра 2 часа', $now);
    check(count($http->calls), 3, 'cache reused');
    Parser::ai($ai, 'Лаба завтра 2 часа', $now);
    check(count($http->calls), 6, 'refresh 401');
    check(strpos($http->calls[0][2], 'GIGACHAT_API_PERS') !== false, true, 'scope');
    check(strpos($http->calls[1][0], 'https://api.giga.chat/v1/chat/completions') === 0, true, 'endpoint');
    $headers = implode('\n', $http->calls[0][1]);
    check((bool)preg_match('/RqUID: [a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/', $headers), true, 'UUID4');
    $http->responses = [['status' => 200,'data' => ['choices' => [['message' => ['content' => '{"tasks":[{"title":"Different","deadline":"2030-01-01T00:00:00Z"}]}']]]]]];
    $drafts = Parser::ai($ai, 'Лаба завтра 2 часа', $now);
    check($drafts[0]['deadline'], Dates::utc($now->modify('+1 day')->setTime(23, 59)), 'user date preserved');
    check($drafts[0]['estimated_minutes'], 120, 'user duration preserved');
    $http->responses = [['status' => 200,'data' => ['choices' => [['message' => ['content' => '{"tasks":[{"title":"Позвонить другу","deadline":"2030-01-01T00:00:00Z","estimated_minutes":780}]}']]]]]];
    $drafts = Parser::ai($ai, 'Завтра позвонить другу', $now);
    check($drafts[0]['estimated_minutes'], null, 'implausible inferred duration is rejected');
});
test('transaction rollback, queue order and recoverable delivery failure', function () {
    $db = database(); $c = config(); $bot = new Bot($db, $c);
    try {
        $db->transaction(function () use ($bot) { $bot->handle(msg('/add')); throw new RuntimeException('simulated crash'); });
    } catch (RuntimeException $e) {}
    check($db->one('SELECT * FROM users WHERE telegram_id=11'), null, 'user rolled back');
    check((int)$db->pdo->query('SELECT count(*) FROM outbox')->fetchColumn(), 0, 'outbox rolled back');
    $db->send(11, 'First'); $db->send(11, 'Second'); $db->send(22, 'Other chat');
    $http = new FakeHttp(); $worker = new Worker($db, $c, new Telegram($c, $http));
    $http->responses = [['status'=>503,'data'=>['ok'=>false,'error_code'=>503]]];
    $worker->deliver(); $worker->deliver();
    check(json_decode($http->calls[1][2],true)['text'], 'Other chat', 'retry does not block other chat');
    check($worker->deliver(), false, 'same chat preserves order');
    $db->run('UPDATE outbox SET available_at=0'); $worker->deliver(); $worker->deliver();
    check(json_decode($http->calls[2][2],true)['text'], 'First', 'first retried');
    check(json_decode($http->calls[3][2],true)['text'], 'Second', 'second follows first');
    $db->send(11, 'Needs recovery');
    $http->responses = [['status'=>400,'data'=>['ok'=>false,'error_code'=>400]]]; $worker->deliver();
    $failed=$db->one('SELECT * FROM outbox WHERE failed_at IS NOT NULL');
    check($failed['sent_at'], null, 'not falsely marked sent');
    check(json_decode($failed['payload'],true)['text'], 'Needs recovery', 'failed payload preserved');
});
test('disabled reminders requeue after enabling and plain emoji switch', function () {
    $db=database(); $c=config(); $bot=new Bot($db,$c); handle($db,$bot,msg('/start')); $db->run('DELETE FROM outbox');
    $now=$c->now(); $db->create(11,['title'=>'Test','deadline'=>Dates::utc($now->modify('+2 hours')),'estimated_minutes'=>60,'importance'=>2],$now);
    $http=new FakeHttp(); $worker=new Worker($db,$c,new Telegram($c,$http));
    $worker->reminders(); $db->run('UPDATE users SET reminders_disabled=1'); $worker->deliver(); check(count($http->calls),0,'disabled skips send');
    $db->run('UPDATE users SET reminders_disabled=0'); $worker->reminders(); $worker->deliver(); check(count($http->calls),1,'reenabled sends');
    $plain = new Telegram(
        new Config(dirname(__DIR__, 2), [
            'BOT_TOKEN' => '123:test',
            'CUSTOM_EMOJI_ENABLED' => 'false',
            'TELEGRAM_RELAY_URL' => 'https://relay.example/relay.php',
            'TELEGRAM_RELAY_WEBHOOK_URL' => 'https://relay.example/webhook-url.php',
            'TELEGRAM_RELAY_TOKEN' => str_repeat('b', 64),
            'TELEGRAM_RELAY_PIN' => 'sha256//' . str_repeat('A', 43) . '=',
        ]),
        $http,
    );
    $plain->request('sendMessage',['text'=>Emojis::render('✅ Готово'),'reply_markup'=>Emojis::inline([[['✅ Готово','done']]])]);
    $payload=json_decode($http->calls[1][2],true);
    check($payload['text'],'✅ Готово','plain text'); check(isset($payload['reply_markup']['inline_keyboard'][0][0]['icon_custom_emoji_id']),false,'plain button');
});
test('cancel clears form, expired sessions and migration version guard', function () {
    $db=database(); $c=config(); $bot=new Bot($db,$c); handle($db,$bot,msg('/add')); handle($db,$bot,msg('/cancel')); check($db->state(11),[],'cancel');
    handle($db,$bot,msg('/add')); $db->run('UPDATE sessions SET updated_at=?',[time()-86401]); check($db->state(11),[],'expired session');
    $db->pdo->exec('PRAGMA user_version=99'); $rejected=false; try { $db->initialize(); } catch (RuntimeException $e) { $rejected=true; }
    check($rejected,true,'future schema rejected'); check((int)$db->pdo->query('PRAGMA user_version')->fetchColumn(),99,'future schema untouched');
});
test('missing duration is requested and can be hidden or entered manually', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    handle($db, $bot, msg('Лаба завтра', 2));
    $state = $db->state(11);
    check($state['step'], 'duration', 'duration requested');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    $labels = [];
    foreach ($payload['reply_markup']['inline_keyboard'] as $row) {
        foreach ($row as $button) {
            $labels[] = $button['text'];
        }
    }
    check($labels, ['До 10 минут', 'До 60 минут', 'Больше 120 минут', 'Указать своё', 'Не указывать'], 'five duration choices');
    handle($db, $bot, callback('duration:' . $state['token'] . ':custom', 3));
    check($db->state(11)['step'], 'duration_custom', 'custom duration input');
    handle($db, $bot, msg('45 минут', 4));
    $state = $db->state(11);
    check($state['step'], 'priority', 'priority after custom duration');
    handle($db, $bot, callback('priority:' . $state['token'] . ':skip', 5));
    $task = $db->tasks(11)[0];
    check((int) $task['estimated_minutes'], 45, 'custom duration saved');
    check((int) $task['duration_visible'], 1, 'custom duration shown');

    handle($db, $bot, msg('/add', 6));
    handle($db, $bot, msg('Отчёт послезавтра', 7));
    $state = $db->state(11);
    handle($db, $bot, callback('duration:' . $state['token'] . ':skip', 8));
    $state = $db->state(11);
    handle($db, $bot, callback('priority:' . $state['token'] . ':skip', 9));
    $task = $db->tasks(11)[1];
    check((int) $task['estimated_minutes'], 60, 'hidden duration keeps planning estimate');
    check((int) $task['duration_visible'], 0, 'duration hidden');
    check(strpos(Planner::card($task, $c->now()), 'мин на задачу'), false, 'hidden duration omitted from card');
});
test('onboarding consent, English switch and navigation keep tasks', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $state = $db->state(11);
    check($state['step'], 'onboarding', 'onboarding waits for consent');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['rich_message']['markdown'], 'Telegram ID') !== false, true, 'Telegram ID collection disclosed');
    check(strpos($payload['rich_message']['markdown'], 'username') === false, true, 'username is absent from disclosure');
    check(strpos($payload['rich_message']['markdown'], 'часовой пояс') === false, true, 'detailed settings list removed from disclosure');
    check(strpos($payload['rich_message']['markdown'], 'GigaChat') !== false, true, 'AI processing disclosed');
    check($payload['reply_markup']['inline_keyboard'][0][0]['text'], 'Переключиться на английский (English)', 'welcome language button');
    check($payload['reply_markup']['inline_keyboard'][1][0]['text'], 'ОК', 'consent button');
    $consentCallback = callback('onboard:' . $state['token'] . ':continue', 2);
    $consentCallback['callback_query']['message']['rich_message'] = ['blocks' => []];
    handle($db, $bot, $consentCallback);
    check((int) $db->one('SELECT ai_consent FROM users WHERE telegram_id=11')['ai_consent'], 1, 'consent stored');
    check($db->state(11)['step'], 'timezone_input', 'onboarding asks current time');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], 'Сколько у вас сейчас времени?') !== false, true, 'timezone question sent separately');
    check(strpos($payload['text'], '<b>Сколько у вас сейчас времени?</b>') !== false, true, 'timezone question has bold heading');
    check(strpos($payload['text'], '<i>Например: 14:' . $c->now()->format('i') . '</i>') !== false, true, 'timezone example uses server minutes');
    $deletedWelcome = $db->one("SELECT payload FROM outbox WHERE method='deleteMessage' ORDER BY id DESC LIMIT 1");
    check($deletedWelcome !== null, true, 'onboarding deletes welcome after consent');
    check(
        json_decode($deletedWelcome['payload'], true)['message_id'],
        $consentCallback['callback_query']['message']['message_id'],
        'onboarding deletes correct welcome message',
    );
    handle($db, $bot, msg($c->now()->format('H:i'), 20));
    check((int) $db->one('SELECT timezone_confirmed FROM users WHERE telegram_id=11')['timezone_confirmed'], 1, 'timezone confirmed');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], 'Готово, у вас UTC ') === 0, true, 'ready message shows detected UTC offset');
    check(strpos($payload['text'], '. Добавьте первое задание.') !== false, true, 'ready message invites first task');
    check($payload['reply_markup']['inline_keyboard'][0][0]['text'], 'Добавить', 'inline add button');
    handle($db, $bot, callback('nav:add', 21));
    check($db->state(11)['step'], 'ai_input', 'inline add opens task input');
    $db->create(11, ['title' => 'Не удалять','deadline' => Dates::utc($c->now()->modify('+1 day')),'estimated_minutes' => 30,'importance' => 2], $c->now());
    handle($db, $bot, msg('/help', 3));
    $state = $db->state(11);
    handle($db, $bot, callback('help:' . $state['token'] . ':tasks', 4));
    handle($db, $bot, msg('/explain', 5));
    $state = $db->state(11);
    handle($db, $bot, callback('ai:' . $state['token'] . ':provider', 6));
    check(count($db->tasks(11)), 1, 'help and explain preserve tasks');
    handle($db, $bot, msg('/settings', 7));
    $state = $db->state(11);
    handle($db, $bot, callback('settings:' . $state['token'] . ':language', 8));
    handle($db, $bot, callback('settings:' . $state['token'] . ':en', 9));
    check($db->one('SELECT language FROM users WHERE telegram_id=11')['language'], 'en', 'English saved');
    check((int) $db->one("SELECT count(*) AS total FROM outbox WHERE method='deleteMessage'")['total'] > 0, true, 'old language settings message is deleted');
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check($payload['text'], 'Language changed to English.', 'language change uses new message');
    check($payload['reply_markup']['keyboard'][0][0]['text'], 'Today', 'main keyboard translated immediately');
    handle($db, $bot, msg('/tasks', 10));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], 'All tasks') !== false, true, 'task list translated');
});
test('AI thinking messages are edited and task input is limited to 200 characters', function () {
    $db = database();
    $c = new Config(dirname(__DIR__, 2), [
        'TIMEZONE' => 'Asia/Yekaterinburg',
        'BOT_TOKEN' => '123:test',
        'TELEGRAM_RELAY_URL' => 'https://relay.example/relay.php',
        'TELEGRAM_RELAY_WEBHOOK_URL' => 'https://relay.example/webhook-url.php',
        'TELEGRAM_RELAY_TOKEN' => str_repeat('b', 64),
        'TELEGRAM_RELAY_PIN' => 'sha256//' . str_repeat('A', 43) . '=',
        'WEBHOOK_SECRET' => str_repeat('a', 32),
        'GIGACHAT_ENABLED' => 'true',
        'GIGACHAT_AUTH_KEY' => 'base64key',
    ]);
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    $thinking = $bot->thinking(msg('Лаба завтра', 2));
    check($thinking['method'], 'sendMessage', 'task thinking message');
    check(strpos($thinking['payload']['text'], 'Уже почти создали задачу') !== false, true, 'task thinking text');
    check($thinking['payload']['reply_markup']['keyboard'][0][0]['text'], 'Отменить', 'cancel keyboard while processing');
    handle($db, $bot, msg('Отменить', 2));
    $cancelPayload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($cancelPayload['text'], 'Ввод отменён.') !== false, true, 'processing cancel button works');
    handle($db, $bot, msg('Лаба завтра', 2), [
        '_thinking_message_id' => 77,
        'source' => 'GigaChat',
        'drafts' => [[
            'title' => 'Лаба',
            'deadline' => Dates::utc($c->now()->modify('+1 day')),
            'estimated_minutes' => 30,
            'importance' => 2,
        ]],
    ]);
    $row = $db->one('SELECT method,payload FROM outbox ORDER BY id DESC LIMIT 1');
    $payload = json_decode($row['payload'], true);
    check($row['method'], 'editMessageText', 'thinking message edited');
    check($payload['message_id'], 77, 'correct thinking message edited');

    handle($db, $bot, msg('/add', 3));
    handle($db, $bot, msg(str_repeat('а', 201), 4));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], '200') !== false, true, 'overlong task rejected');
    check(Parser::local(str_repeat('а', 201), $c->now()), [], 'parser enforces task limit');

    handle($db, $bot, msg('/cancel', 5));
    $db->run('UPDATE users SET ai_consent=1 WHERE telegram_id=11');
    $thinking = $bot->thinking(msg('Купить продукты завтра', 6));
    check($thinking['method'], 'sendMessage', 'free chat task shows AI loading message');
    check(strpos($thinking['payload']['text'], 'Уже почти создали задачу') !== false, true, 'free chat uses task loading text');
});
test('GigaChat explanation uses a short individual recommendation', function () {
    $db = database();
    $c = new Config(dirname(__DIR__, 2), [
        'TIMEZONE' => 'Asia/Yekaterinburg',
        'GIGACHAT_ENABLED' => 'true',
        'GIGACHAT_AUTH_KEY' => 'base64key',
    ]);
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->create(11, [
        'title' => 'Подготовить доклад',
        'deadline' => Dates::utc($c->now()->modify('+1 day')),
        'estimated_minutes' => 90,
        'importance' => 3,
    ], $c->now());
    handle($db, $bot, msg('/explain', 2));
    $state = $db->state(11);
    $callback = callback('ai:' . $state['token'] . ':provider', 3);
    handle($db, $bot, $callback, [
        'steps' => [[
            'task_ref' => 1,
            'reason' => 'deadline',
            'advice' => 'Сначала я бы занялся докладом, потому что до срока остался всего один день.',
        ]],
    ]);
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], 'Сначала я бы занялся докладом') !== false, true, 'sequential AI advice shown');
    check(strpos($payload['text'], 'План целиком: 1 ч 30 мин.') !== false, true, 'plan total uses hours and minutes');
});
test('worker sends AI progress before request and edits it after response', function () use ($now) {
    $db = database();
    $c = new Config(dirname(__DIR__, 2), [
        'TIMEZONE' => 'Asia/Yekaterinburg',
        'BOT_TOKEN' => '123:test',
        'TELEGRAM_RELAY_URL' => 'https://relay.example/relay.php',
        'TELEGRAM_RELAY_WEBHOOK_URL' => 'https://relay.example/webhook-url.php',
        'TELEGRAM_RELAY_TOKEN' => str_repeat('b', 64),
        'TELEGRAM_RELAY_PIN' => 'sha256//' . str_repeat('A', 43) . '=',
        'WEBHOOK_SECRET' => str_repeat('a', 32),
        'GIGACHAT_ENABLED' => 'true',
        'GIGACHAT_AUTH_KEY' => 'base64key',
    ]);
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/add'));
    $db->run('DELETE FROM outbox');
    $db->enqueue(msg('Лаба завтра 30 минут', 91));
    $http = new FakeHttp();
    $http->responses = [
        ['status' => 200, 'data' => ['ok' => true, 'result' => ['message_id' => 77]]],
        ['status' => 200, 'data' => ['access_token' => 'token', 'expires_at' => (time() + 1800) * 1000]],
        ['status' => 200, 'data' => ['choices' => [['message' => ['content' => '{"tasks":[{"title":"Лаба","subject":null,"deadline":null,"estimated_minutes":30}]}']]]]],
    ];
    $worker = new Worker(
        $db,
        $c,
        new Telegram($c, $http),
        new Bot($db, $c, new GigaChat($c, $db, $http)),
    );
    check($worker->update(), true, 'AI update processed');
    check(substr($http->calls[0][0], -12), '/sendMessage', 'progress sent first');
    check($worker->deliver(), true, 'loading message removed');
    check(substr($http->calls[3][0], -14), '/deleteMessage', 'keyboard progress is deleted rather than edited');
    $payload = json_decode($http->calls[3][2], true);
    check($payload['message_id'], 77, 'delete targets progress message');
    check($worker->deliver(), true, 'AI result delivered');
    check(substr($http->calls[4][0], -12), '/sendMessage', 'result sent after removing keyboard progress');
});
test('GigaChat failure never silently switches to local task parsing', function () {
    $db = database();
    $bot = new Bot($db, config());
    handle($db, $bot, msg('/add'));
    $bot->handle(msg('Завтра забрать справку после пар', 2), [
        'failed' => true, '_thinking_message_id' => 77, '_thinking_has_keyboard' => true,
    ]);
    check($db->state(11)['step'], 'ai_input', 'failed AI keeps input open');
    check(count($db->tasks(11)), 0, 'no task invented by local fallback');
    $row = $db->one('SELECT method,payload FROM outbox ORDER BY id DESC LIMIT 1');
    check($row['method'], 'sendMessage', 'error replaces uneditable loading with new message');
    check(strpos(json_decode($row['payload'], true)['text'], 'GigaChat не смог') !== false, true, 'AI failure visible');
});
test('uneditable Telegram result is resent without another AI request', function () {
    $db = database();
    $c = config();
    $http = new FakeHttp();
    $http->responses = [[ 'status' => 400, 'data' => [
        'ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be edited",
    ] ]];
    $db->out('editMessageText', ['chat_id' => 11, 'message_id' => 77, 'text' => 'Сколько времени займёт задача?',
        'reply_markup' => Emojis::inline([[['До 10 минут', 'duration:test:10']]]),
    ]);
    $worker = new Worker($db, $c, new Telegram($c, $http));
    $worker->deliver();
    $row = $db->one('SELECT * FROM outbox ORDER BY id DESC LIMIT 1');
    check($row['method'], 'sendMessage', 'rejected edit becomes send');
    check(isset(json_decode($row['payload'], true)['message_id']), false, 'message id removed');
    $worker->deliver();
    check($db->one('SELECT sent_at FROM outbox WHERE id=?', [$row['id']])['sent_at'] !== null, true, 'result delivered');
});
test('welcome language, timezone, grouped tasks and compact statistics', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    check((int) $db->one('SELECT timezone_confirmed FROM users WHERE telegram_id=11')['timezone_confirmed'], 0, 'new timezone is not preconfirmed');
    $state = $db->state(11);
    $switch = callback('onboard:' . $state['token'] . ':language', 2);
    $switch['callback_query']['message']['rich_message'] = ['blocks' => []];
    handle($db, $bot, $switch);
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['rich_message']['markdown'], "# Hi! I'm") !== false, true, 'welcome changes to English');
    check($payload['reply_markup']['inline_keyboard'][0][0]['text'], 'Switch to Russian (Русский)', 'welcome offers Russian after switching');
    check(strpos(json_encode(Emojis::menu(false, 'ru'), JSON_UNESCAPED_UNICODE), 'Неделя'), false, 'week removed from menu');

    $db->create(11, ['title' => 'Поздняя','deadline' => Dates::utc($c->now()->modify('+1 day')->setTime(15, 0)),'estimated_minutes' => 15,'importance' => 2], $c->now());
    $db->create(11, ['title' => 'Ранняя','deadline' => Dates::utc($c->now()->modify('+1 day')->setTime(11, 0)),'estimated_minutes' => 30,'importance' => 3], $c->now());
    $db->create(11, ['title' => 'Без времени','deadline' => Dates::utc($c->now()->modify('+2 days')->setTime(23, 59)),'estimated_minutes' => 20,'importance' => 1], $c->now());
    handle($db, $bot, msg('/tasks', 3));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['text'], '🕒 11:00') < strpos($payload['text'], '🕒 15:00'), true, 'all tasks sorted by time within priority');
    check(strpos($payload['text'], '#1. Ранняя') !== false, true, 'task title uses plain compact format');
    check(strpos($payload['text'], '<b>#1.') === false, true, 'task title is not bold');
    check(strpos($payload['text'], '<b>' . $c->now()->modify('+1 day')->format('d.m.Y') . '</b>') === false, true, 'task date is not bold');
    check(strpos($payload['text'], '━━━━━━━━━━━━') !== false, true, 'task date groups use long separator');
    check(strpos($payload['text'], '23:59') === false, true, 'default end-of-day time is hidden');
    check(substr_count($payload['text'], $c->now()->modify('+1 day')->format('d.m.Y')), 1, 'date shown once for group');
    handle($db, $bot, msg('/stats', 4));
    $payload = json_decode($db->one('SELECT payload FROM outbox ORDER BY id DESC LIMIT 1')['payload'], true);
    check(strpos($payload['rich_message']['markdown'], '| Metric | Value |') !== false, true, 'English statistics use a rich markdown table');
});
test('calendar renders a six-week month and is queued before task text', function () {
    $now = new DateTimeImmutable('2026-08-08T12:00:00+05:00');
    $png = UniFlow\TaskCalendar::render([
        ['deadline' => '2026-08-08T10:00:00+00:00', 'title' => 'Лаба по физике', 'importance' => 3, 'importance_visible' => 1],
    ], $now, '2026-08', 'ru', dirname(__DIR__, 2) . '/public/assets/fonts/NotoSans.ttf');
    $size = getimagesizefromstring($png);
    check($size[0], 1120, 'calendar width');
    check($size[1], 950, 'six weeks fit without clipping');
    check($size['mime'], 'image/jpeg', 'calendar JPEG');
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->create(11, ['title' => 'Задача', 'deadline' => Dates::utc($c->now()->modify('+1 day')), 'estimated_minutes' => 10, 'importance' => 1], $c->now());
    $db->run('DELETE FROM outbox');
    handle($db, $bot, msg('/tasks', 2));
    $methods = array_column($db->run('SELECT method FROM outbox ORDER BY id')->fetchAll(), 'method');
    check($methods, ['sendTaskCalendar'], 'calendar and task list share one message');
    handle($db, $bot, msg('/settings', 3));
    $state = $db->state(11);
    handle($db, $bot, callback('settings:' . $state['token'] . ':calendar', 4));
    $db->run('DELETE FROM outbox');
    handle($db, $bot, msg('/tasks', 5));
    check(array_column($db->run('SELECT method FROM outbox')->fetchAll(), 'method'), ['sendMessage'], 'disabled calendar skips generation and loading');
    handle($db, $bot, msg('/settings', 6));
    $state = $db->state(11);
    handle($db, $bot, callback('settings:' . $state['token'] . ':calendar', 7));
    $db->run('DELETE FROM outbox');
    handle($db, $bot, msg('/tasks', 8));
    check(array_column($db->run('SELECT method FROM outbox')->fetchAll(), 'method'), ['sendTaskCalendar'], 'calendar can be enabled again');
});
echo "\n$passed assertions passed on PHP " . PHP_VERSION . "\n";
