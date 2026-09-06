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
    return new Config(dirname(__DIR__, 2), ['TIMEZONE' => 'Asia/Yekaterinburg','BOT_TOKEN' => '123:test','WEBHOOK_SECRET' => str_repeat('a', 32)]);
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
    $db->transaction(function () use ($bot, $u, $prepared) {
        $bot->handle($u, $prepared);
    });
}
class FakeHttp extends Http
{
    public array $calls = [];
    public array $responses = [];
    public function post(string $url, array $headers, string $body, string $ca = ''): array
    {
        $this->calls[] = [$url,$headers,$body,$ca];
        return array_shift($this->responses) ?? ['status' => 200,'data' => ['ok' => true,'result' => ['message_id' => 1]]];
    }
}
$now = new DateTimeImmutable('2026-09-05T12:00:00+05:00');
test('dates, UTC, invalid input, DST and duration', function () use ($now) {
    check(Dates::parse('завтра в 18:00', $now), '2026-09-06T13:00:00.000000+00:00', 'local to UTC');
    check(Dates::parse('сегодня', $now), '2026-09-05T18:59:00.000000+00:00', 'default time');
    check(Dates::parse('31.02.2027', $now), null, 'invalid day');
    check(Dates::parse('31.04', $now), null, 'invalid month day');
    check(Dates::parse('завтра в 25:10', $now), null, 'invalid clock');
    check(Dates::parse('завтра мусор', $now), null, 'reject trailing text');
    check(Dates::parse('29.02', $now), '2028-02-29T18:59:00.000000+00:00', 'leap year');
    check(Dates::parse('в пятницу', $now), '2026-09-11T18:59:00.000000+00:00', 'weekday');
    check(Dates::parse('10 сентября', $now), '2026-09-10T18:59:00.000000+00:00', 'month');
    check(Dates::parse('через 2 недели', $now), '2026-09-19T18:59:00.000000+00:00', 'weeks');
    check(Dates::parse('29.03.2026 в 02:30', new DateTimeImmutable('2026-03-01', new DateTimeZone('Europe/Berlin'))), null, 'nonexistent time');
    check(Dates::duration('1.5 часа'), 90, 'fraction');
    check(Dates::duration('1 час 30 минут'), 90, 'compound');
    check(Dates::duration('полтора часа'), 90, 'words');
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
test('schema migration preserves existing tasks and timestamps', function () {
    $db = new Database(':memory:');
    $db->pdo->exec("CREATE TABLE users(telegram_id INTEGER PRIMARY KEY,username TEXT,full_name TEXT,timezone TEXT NOT NULL,created_at DATETIME NOT NULL,reminders_disabled INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(telegram_id),title TEXT NOT NULL,subject TEXT,deadline DATETIME NOT NULL,estimated_minutes INTEGER NOT NULL DEFAULT 60,importance INTEGER NOT NULL DEFAULT 2,status TEXT NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,completed_at DATETIME,reminder_24h_sent INTEGER NOT NULL DEFAULT 0,reminder_3h_sent INTEGER NOT NULL DEFAULT 0,is_demo INTEGER NOT NULL DEFAULT 0);
    INSERT INTO users VALUES(11,NULL,NULL,'Europe/Moscow','2026-01-01T00:00:00.000000+00:00',0);
    INSERT INTO tasks(user_id,title,deadline,created_at) VALUES(11,'Old task','2026-09-05T19:30:00.000000+00:00','2026-01-01T00:00:00.000000+00:00');");
    $db->initialize();
    $db->initialize();
    check($db->tasks(11)[0]['deadline'], '2026-09-05T19:30:00.000000+00:00', 'deadline preserved');
    check((int)$db->pdo->query('PRAGMA user_version')->fetchColumn(), 2, 'version');
    check((int)$db->tasks(11)[0]['revision'], 0, 'revision');
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
test('full manual form, persistence, ownership, edit, stale callback and completion', function () {
    $db = database();
    $c = config();
    foreach (['/add','<b>Лаба</b>','Java','завтра в 18:00','1.5 часа','Очень важная'] as $i => $text) {
        handle($db, new Bot($db, $c), msg($text, $i));
    }
    $tasks = $db->tasks(11);
    check(count($tasks), 1, 'task saved');
    $id = (int)$tasks[0]['id'];
    check((int)$tasks[0]['estimated_minutes'], 90, 'duration');
    check($db->state(11), [], 'state consumed');
    $bot = new Bot($db, $c);
    handle($db, $bot, callback('task:done:' . $id, 1, 22));
    check(count($db->tasks(11)), 1, 'ownership');
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
    handle($db, $bot, callback('task:delete:' . $id));
    $s = $db->state(11);
    handle($db, $bot, callback('delete:' . $s['token'] . ':no'));
    check(count($db->tasks(11)), 1, 'cancel deletion');
    handle($db, $bot, callback('task:done:' . $id));
    check(count($db->tasks(11)), 0, 'completed');
    check(count($db->tasks(11, false)), 1, 'history');
});
test('parser preview, missing fields, review edit and no duplicate saves', function () use ($now) {
    $parsed = Parser::local('Лаба по Java до пятницы 2 часа', $now);
    check(count($parsed), 1, 'parse');
    check($parsed[0]['estimated_minutes'], 120, 'parse duration');
    check(Parser::local('привет', $now), [], 'not task');
    check(Parser::local('Сдать отчёт Т', $now)[0]['title'], 'Сдать отчёт Т', 'Cyrillic boundary remains valid');
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('Сделать лабораторную'));
    check(count($db->tasks(11)), 0, 'preview only');
    $s = $db->state(11);
    handle($db, $bot, callback('review:' . $s['token'] . ':add'));
    check($db->state(11)['field'], 'deadline', 'ask date');
    handle($db, $bot, msg('завтра'));
    check($db->state(11)['field'], 'estimated_minutes', 'ask duration');
    handle($db, $bot, msg('30 минут'));
    $s = $db->state(11);
    $token = $s['token'];
    handle($db, $bot, callback('review:' . $token . ':add'));
    handle($db, $bot, callback('review:' . $token . ':add'));
    check(count($db->tasks(11)), 1, 'confirmed only once');
});
test('planning, week, pagination, settings, demo and local AI routes', function () use ($now) {
    $t = ['id' => 1,'deadline' => Dates::utc($now->modify('+2 hours')),'estimated_minutes' => 240,'importance' => 3];
    check(Planner::priority($t, $now), 4, 'urgent');
    check(Planner::minutes(240, $t['deadline'], $now), 240, 'uncapped urgent');
    check(Planner::minutes(100, Dates::utc($now->modify('+10 days')), $now), 10, 'spread');
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/demo'));
    $s = $db->state(11);
    handle($db, $bot, callback('demo:' . $s['token'] . ':yes'));
    check(count($db->tasks(11)), 4, 'demo');
    foreach (['/today','/tasks','/week','/stats','/help','/settings'] as $cmd) {
        handle($db, $bot, msg($cmd));
    }
    $s = $db->state(11);
    handle($db, $bot, callback('settings:' . $s['token'] . ':reminders'));
    check((int)$db->one('SELECT reminders_disabled FROM users WHERE telegram_id=11')['reminders_disabled'], 1, 'settings');
    handle($db, $bot, msg('/explain'));
    $s = $db->state(11);
    handle($db, $bot, callback('ai:' . $s['token'] . ':local'));
    check($db->state(11), [], 'explain finished');
    handle($db, $bot, msg('/ai'));
    $s = $db->state(11);
    handle($db, $bot, callback('ai:' . $s['token'] . ':local'));
    handle($db, $bot, msg('Лаба завтра 2 часа'));
    check($db->state(11)['step'], 'review', 'local ai');
    foreach ($db->run("SELECT payload FROM outbox WHERE method='sendMessage'")->fetchAll() as $row) {
        $p = json_decode($row['payload'], true);
        check(mb_strlen(strip_tags($p['text'])) <= 4096, true, 'Telegram length');
        foreach ($p['reply_markup']['inline_keyboard'] ?? [] as $buttons) {
            foreach ($buttons as $b) {
                check(isset($b['text'], $b['callback_data']), true, 'button shape');
                check(strlen($b['callback_data']) <= 64, true, 'callback limit');
            }
        }
    }
});
test('reminder uniqueness, revision invalidation and retry after', function () {
    $db = database();
    $c = config();
    $bot = new Bot($db, $c);
    handle($db, $bot, msg('/start'));
    $db->pdo->exec('DELETE FROM outbox');
    $now = $c->now();
    $db->create(11, ['title' => 'Deadline','subject' => null,'deadline' => Dates::utc($now->modify('+5 hours')),'estimated_minutes' => 60,'importance' => 2], $now);
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
    check($drafts[0]['deadline'], null, 'ungrounded date discarded');
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
    $plain=new Telegram(new Config(dirname(__DIR__,2),['BOT_TOKEN'=>'123:test','CUSTOM_EMOJI_ENABLED'=>'false']),$http);
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
echo "\n$passed assertions passed on PHP " . PHP_VERSION . "\n";
