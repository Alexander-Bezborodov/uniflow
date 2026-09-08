<?php

declare(strict_types=1);

namespace UniFlow;

final class Bot
{
    private Database $db;
    private Config $config;
    private GigaChat $ai;
    private int $user = 0;
    private array $s = [];
    private \DateTimeImmutable $now;
    private ?int $editMessageId = null;
    private ?int $incomingMessageId = null;
    private bool $editHasPhoto = false;
    private bool $profileDeleted = false;
    private string $language = 'ru';

    public const FIELDS = ['title', 'subject', 'deadline', 'estimated_minutes', 'importance'];
    private const LABELS = [
        'title' => 'Название',
        'subject' => 'Предмет',
        'deadline' => 'Дедлайн',
        'estimated_minutes' => 'Длительность',
    ];

    public function __construct(Database $db, Config $config, ?GigaChat $ai = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->ai = $ai ?? new GigaChat($config, $db);
    }

    public static function identity(array $u): ?array
    {
        $m = $u['message'] ?? ($u['callback_query']['message'] ?? null);
        $from = $u['callback_query']['from'] ?? ($m['from'] ?? null);
        if (
            !is_array($m) ||
            !is_array($from) ||
            ($m['chat']['type'] ?? '') !== 'private' ||
            !isset($from['id']) ||
            !is_int($from['id']) ||
            ($m['chat']['id'] ?? null) !== $from['id'] ||
            ($from['is_bot'] ?? false)
        ) {
            return null;
        }
        return $from;
    }

    public function prepare(array $u): array
    {
        $from = self::identity($u);
        if (!$from) {
            return [];
        }
        $state = $this->db->state($from['id']);
        $now = $this->config->now();
        $text = $u['message']['text'] ?? '';
        $callback = $u['callback_query']['data'] ?? '';
        $user = $this->db->one('SELECT ai_consent,language,timezone FROM users WHERE telegram_id=?', [$from['id']]);
        if (!empty($user['timezone'])) {
            $now = $now->setTimezone(new \DateTimeZone($user['timezone']));
        }
        if (!$this->ai->available()) {
            return [];
        }
        $parse = $this->shouldParseWithAi($state, $user, $text);
        $explain =
            ($state['step'] ?? '') === 'ai_choice' &&
            ($state['action'] ?? '') === 'explain' &&
            $callback === 'ai:' . ($state['token'] ?? '') . ':provider';
        if (!$parse && !$explain) {
            return [];
        }
        $rate = $this->db->getCache('ai_rate_' . $from['id']);
        if ($rate && (int) $rate['value'] > time() - 2) {
            return ['failed' => true];
        }
        $this->db->setCache('ai_rate_' . $from['id'], (string) time());
        try {
            if ($parse) {
                return ['drafts' => Parser::ai($this->ai, $text, $now), 'source' => 'GigaChat'];
            }
            $plan = array_slice(Planner::plan($this->db->tasks($from['id']), $now), 0, 3);
            if (!$plan) {
                return [];
            }
            $facts = [];
            foreach ($plan as $i => $t) {
                $facts[] = [
                    'task_ref' => $i + 1,
                    'title' => $t['title'],
                    'deadline' => Dates::label($t['deadline'], $now),
                    'estimated_minutes' => (int) $t['estimated_minutes'],
                    'importance' => (int) $t['importance'],
                    'allowed_reasons' => Planner::reasons($t, $now),
                ];
            }
            $english = ($user['language'] ?? 'ru') === 'en';
            $instruction = $english
                ? 'Create a logical sequence using the supplied task order. For task 1 start advice with "First", for task 2 with "Then", and for task 3 with "After that". Choose one reason from allowed_reasons and briefly explain the position using only deadline, duration, and importance. Do not say that every task should be started now. Do not invent dependencies. Return only JSON {"steps":[{"task_ref":1,"reason":"deadline","advice":"First, I would handle this task because it has the closest deadline."}]}. advice must contain 10-220 characters and one short sentence. Keep the task order and count.'
                : 'Составь логичную последовательность в переданном порядке задач. Совет для задачи 1 начни со слова «Сначала», для задачи 2 - «Затем», для задачи 3 - «После этого». Выбери одну причину из allowed_reasons и кратко объясни место задачи в порядке, используя только срок, длительность и важность. Не говори, что каждую задачу нужно начать сейчас. Не выдумывай зависимости между задачами. Верни только JSON {"steps":[{"task_ref":1,"reason":"deadline","advice":"Сначала я бы занялся этой задачей, потому что у неё ближайший срок."}]}. advice: 10-220 символов, одно короткое предложение. Сохрани порядок и количество задач.';
            $result = $this->ai->complete(
                $instruction,
                json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            if (
                !isset($result['steps']) ||
                !is_array($result['steps']) ||
                count($result['steps']) !== count($facts)
            ) {
                throw new \RuntimeException('invalid_reasons');
            }
            $adviceSeen = [];
            $prefixes = $english
                ? ['First', 'Then', 'After that']
                : ['Сначала', 'Затем', 'После этого'];
            foreach ($result['steps'] as $i => $step) {
                if (
                    ($step['task_ref'] ?? null) !== $i + 1 ||
                    !in_array($step['reason'] ?? '', $facts[$i]['allowed_reasons'], true) ||
                    !isset($step['advice']) ||
                    !is_string($step['advice']) ||
                    mb_strlen(trim($step['advice'])) < 10 ||
                    mb_strlen($step['advice']) > 220
                ) {
                    throw new \RuntimeException('invalid_reason');
                }
                if (
                    !preg_match(
                        '~^' . preg_quote($prefixes[min($i, 2)], '~') . '\b~iu',
                        trim($step['advice']),
                    )
                ) {
                    throw new \RuntimeException('invalid_sequence');
                }
                $adviceKey = mb_strtolower(trim($step['advice']));
                if (isset($adviceSeen[$adviceKey])) {
                    throw new \RuntimeException('repeated_advice');
                }
                $adviceSeen[$adviceKey] = true;
            }
            return ['steps' => $result['steps'], 'source' => 'GigaChat'];
        } catch (\Throwable $e) {
            error_log('GigaChat fallback: ' . get_class($e) . ': ' . $e->getMessage());
            return ['failed' => true];
        }
    }

    public function thinking(array $u): ?array
    {
        $from = self::identity($u);
        if (!$from) {
            return null;
        }
        $state = $this->db->state($from['id']);
        $user = $this->db->one('SELECT ai_consent,language FROM users WHERE telegram_id=?', [$from['id']]);
        $language = ($user['language'] ?? 'ru') === 'en' ? 'en' : 'ru';
        $text = $u['message']['text'] ?? '';
        $callback = $u['callback_query']['data'] ?? '';
        if ($this->shouldParseWithAi($state, $user, $text)) {
            return [
                'method' => 'sendMessage',
                'payload' => [
                    'chat_id' => $from['id'],
                    'text' => Emojis::render(
                        $language === 'en'
                            ? '⏳ Almost done creating the task...'
                            : '⏳ Уже почти создали задачу...',
                    ),
                    'parse_mode' => 'HTML',
                    'reply_markup' => Emojis::keyboard([
                        [$language === 'en' ? '❌ Cancel' : '❌ Отменить'],
                    ]),
                ],
            ];
        }
        if (
            ($state['step'] ?? '') === 'ai_choice' &&
            ($state['action'] ?? '') === 'explain' &&
            $callback === 'ai:' . ($state['token'] ?? '') . ':provider' &&
            isset($u['callback_query']['message']['message_id'])
        ) {
            return [
                'method' => 'editMessageText',
                'payload' => [
                    'chat_id' => $from['id'],
                    'message_id' => $u['callback_query']['message']['message_id'],
                    'text' => Emojis::render(
                        $language === 'en'
                            ? '⏳ GigaChat is preparing an explanation...'
                            : '⏳ GigaChat готовит объяснение...',
                    ),
                    'parse_mode' => 'HTML',
                ],
            ];
        }
        return null;
    }

    private function shouldParseWithAi(array $state, ?array $user, $text): bool
    {
        $step = $state['step'] ?? '';
        $acceptsTask = $step === '' || ($step === 'ai_input' && ($state['provider'] ?? false));
        $cleanText = is_string($text) ? trim($text) : '';
        return
            $acceptsTask &&
            !empty($user['ai_consent']) &&
            $cleanText !== '' &&
            mb_strlen($cleanText) <= 200 &&
            $cleanText[0] !== '/' &&
            $this->action($cleanText) === null;
    }

    public function handle(array $u, array $prepared = []): void
    {
        $this->profileDeleted = false;
        $from = self::identity($u);
        if (!$from) {
            return;
        }
        $this->user = $from['id'];
        $this->now = $this->config->now();
        $existingProfile = $this->db->one('SELECT telegram_id FROM users WHERE telegram_id=?', [
            $this->user,
        ]);
        $this->db->upsertUser([
                $this->user,
                null,
                trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')),
                $this->now->getTimezone()->getName(),
                Dates::utc($this->now),
            ]);
        $user = $this->db->one('SELECT language,timezone FROM users WHERE telegram_id=?', [$this->user]);
        $this->language = ($user['language'] ?? 'ru') === 'en' ? 'en' : 'ru';
        if (!empty($user['timezone'])) {
            $this->now = $this->now->setTimezone(new \DateTimeZone($user['timezone']));
        }
        $this->s = $this->db->state($this->user);
        $this->editMessageId = null;
        $this->editHasPhoto = false;
        $this->incomingMessageId = null;
        if (isset($u['message']['message_id']) && is_int($u['message']['message_id'])) {
            $this->incomingMessageId = $u['message']['message_id'];
        }
        $incomingText = isset($u['message']['text']) && is_string($u['message']['text'])
            ? trim(Emojis::normalize($u['message']['text']))
            : '';
        if (!$existingProfile && $this->action($incomingText) !== 'start') {
            if (isset($u['callback_query']['id'])) {
                $this->db->out('answerCallbackQuery', [
                    'callback_query_id' => $u['callback_query']['id'],
                ]);
            }
            $this->s = [];
            $this->navigate('start');
            $this->db->saveState($this->user, $this->s);
            return;
        }
        if (isset($prepared['_thinking_message_id']) && is_int($prepared['_thinking_message_id'])) {
            if (!empty($prepared['_thinking_has_keyboard'])) {
                $this->db->out('deleteMessage', [
                    'chat_id' => $this->user,
                    'message_id' => $prepared['_thinking_message_id'],
                ]);
            } else {
                $this->editMessageId = $prepared['_thinking_message_id'];
            }
        }
        if (isset($u['callback_query'])) {
            $q = $u['callback_query'];
            if (isset($q['message']['message_id']) && is_int($q['message']['message_id'])) {
                $this->editMessageId = $q['message']['message_id'];
                $this->editHasPhoto = !empty($q['message']['photo']);
            }
            $this->db->out('answerCallbackQuery', ['callback_query_id' => $q['id']]);
            $this->callback((string) ($q['data'] ?? ''), $prepared);
        } else {
            $text = $u['message']['text'] ?? null;
            if (isset($u['message']['message_id']) && is_int($u['message']['message_id'])) {
                $this->incomingMessageId = $u['message']['message_id'];
            }
            if (!is_string($text) || trim($text) === '') {
                $this->say($this->l('Отправь задание текстом или выбери действие.', 'Send a task as text or choose an action.'), $this->menu());
            } else {
                $this->message(trim(Emojis::normalize($text)), $prepared);
            }
        }
        if (!$this->profileDeleted) {
            $this->db->saveState($this->user, $this->s);
        }
    }

    private function say(string $text, ?array $keyboard = null): void
    {
        if ($this->editMessageId !== null) {
            $payload = [
                'chat_id' => $this->user,
                'message_id' => $this->editMessageId,
                'text' => Emojis::render($text),
                'parse_mode' => 'HTML',
            ];
            if ($keyboard !== null && isset($keyboard['inline_keyboard'])) {
                $payload['reply_markup'] = $keyboard;
            }
            $this->db->out('editMessageText', $payload);
            $this->editMessageId = null;
            return;
        }
        $this->db->send($this->user, $text, $keyboard);
    }

    private function dismissStaleButton(): void
    {
        if ($this->editMessageId === null) {
            return;
        }
        $this->db->out('editMessageReplyMarkup', [
            'chat_id' => $this->user,
            'message_id' => $this->editMessageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
        $this->editMessageId = null;
        $this->editHasPhoto = false;
    }

    private function menu(): array
    {
        return Emojis::menu(count($this->db->tasks($this->user)) > 1, $this->language);
    }

    private function l(string $ru, string $en): string
    {
        return $this->language === 'en' ? $en : $ru;
    }

    private function welcomeMarkdown(): string
    {
        $image = $this->config->get(
            'WELCOME_IMAGE_RELAY_URL',
        );
        return '![](' . $image . ")\n\n" . $this->l(
            "# Привет! Я UniFlow\n\nБот сохраняет ваш Telegram ID, чтобы связать задания с аккаунтом. Текст задания передаётся GigaChat для обработки. Все данные можно удалить в настройках.",
            "# Hi! I'm UniFlow\n\nThe bot stores your Telegram ID to link tasks to your account. Your task text is sent to GigaChat for processing. You can delete all data in settings.",
        );
    }

    private function taskExample(): string
    {
        $examples = $this->language === 'en'
            ? [
                'Physics lab tomorrow at 1 PM',
                'Submit my history essay on Friday',
                'Finish the presentation by Monday',
                'Send the algebra homework tomorrow at 6 PM',
                'Buy a birthday present the day after tomorrow',
                'Dentist appointment on Saturday at noon',
                'Read the literature chapter by September 15',
                'Prepare for the test tonight',
                'Pay for the internet in three days',
                'Finish the term paper on Sunday, about 3 hours',
                'Pick up a certificate tomorrow after class',
                'Solve the calculus problems by Thursday',
                'Call the project team at 4:30 PM',
                'Send the corrected report to the teacher tonight',
                'Register for the retake on Monday',
                'Return the library book next week',
                'Buy notebooks and pens tomorrow',
                'Learn the English words by Wednesday',
                'Call grandma tonight at 9 PM',
                'Collect materials for the report by Friday',
                'Pick up the order on Tuesday',
                'Review the exam topics tomorrow morning',
                'Pay for the dormitory on September 20',
                'Go to training the day after tomorrow',
                'Edit the video by Sunday',
                'Send my resume after class today',
                'Check the test results in two days',
                'Prepare questions for the seminar on Thursday',
                'Clean my room before lunch tomorrow',
                'Finish the mockup by Saturday',
                'Meet the tutor on September 12',
                'Buy groceries today at 7 PM',
                'Read the article before the next class',
                'Book the tickets on Friday',
                'Renew the subscription in five days',
            ]
            : [
                'Завтра лаба по физике в 13 часов дня',
                'В пятницу сдать эссе по истории',
                'До понедельника доделать презу',
                'Завтра в 18:00 отправить домашку по алгебре',
                'Послезавтра купить подарок маме',
                'В субботу к 12 сходить к стоматологу',
                'До 15 сентября прочитать главу по литре',
                'Сегодня вечером подготовиться к контрольной',
                'Через 3 дня оплатить интернет',
                'В воскресенье закончить курсовую, часа 3',
                'Завтра забрать справку после пар',
                'К четвергу решить задачи по матану',
                'В 16:30 созвониться с командой по проекту',
                'До вечера отправить преподу исправленный отчёт',
                'В понедельник записаться на пересдачу',
                'Через неделю вернуть книгу в библиотеку',
                'Завтра купить тетради и ручки',
                'К среде выучить слова по английскому',
                'Сегодня в 21:00 позвонить бабушке',
                'До пятницы собрать материалы для доклада',
                'Во вторник забрать заказ',
                'Завтра утром повторить билеты',
                '20 сентября оплатить общежитие',
                'Послезавтра сходить на тренировку',
                'К воскресенью смонтировать видео',
                'Сегодня после пар отправить резюме',
                'Через 2 дня проверить результаты анализов',
                'В четверг подготовить вопросы к семинару',
                'Завтра до обеда убрать комнату',
                'К субботе закончить макет',
                '12 сентября встретиться с куратором',
                'Сегодня в 19:00 купить продукты',
                'До следующей пары прочитать статью',
                'В пятницу забронировать билеты',
                'Через 5 дней продлить подписку',
            ];
        $cacheKey = 'task_examples_' . $this->user;
        $cached = $this->db->getCache($cacheKey);
        $recent = $cached ? json_decode($cached['value'], true) : [];
        if (!is_array($recent)) {
            $recent = [];
        }
        $recent = array_values(array_filter($recent, function ($index) use ($examples): bool {
            return is_int($index) && isset($examples[$index]);
        }));
        $available = array_values(array_diff(array_keys($examples), $recent));
        $index = $available[random_int(0, count($available) - 1)];
        $recent[] = $index;
        $this->db->setCache($cacheKey, json_encode(array_slice($recent, -5), JSON_THROW_ON_ERROR));
        return $examples[$index];
    }

    private function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . $this->l(' мин', ' min');
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours .
            $this->l(' ч', ' h') .
            ($rest > 0 ? ' ' . $rest . $this->l(' мин', ' min') : '');
    }

    private function token(): string
    {
        return bin2hex(random_bytes(6));
    }

    private function action(string $text): ?string
    {
        if (
            preg_match(
                '~^/(start|help|cancel|add|today|tasks|week|stats|settings|ai|explain)(?:@[A-Za-z0-9_]+)?(?:\s|$)~',
                $text,
                $m,
            )
        ) {
            return $m[1];
        }
        $plain = trim(preg_replace('~^[^\p{L}\p{N}]+~u', '', $text));
        $map = [
            'Сегодня' => 'today',
            'Добавить задачу' => 'add',
            'Добавить задание' => 'add',
            'Все задачи' => 'tasks',
            'Неделя' => 'week',
            'Статистика' => 'stats',
            'Помощь' => 'help',
            'Настройки' => 'settings',
            'Объяснить план' => 'explain',
            'Отмена' => 'cancel',
            'Отменить' => 'cancel',
            'Today' => 'today',
            'Add task' => 'add',
            'All tasks' => 'tasks',
            'Week' => 'week',
            'Statistics' => 'stats',
            'Help' => 'help',
            'Settings' => 'settings',
            'Explain plan' => 'explain',
            'Cancel' => 'cancel',
        ];
        return $map[$plain] ?? null;
    }

    private function message(string $text, array $prepared): void
    {
        $step = $this->s['step'] ?? '';
        $action = $this->action($text);
        if ($step === 'timezone_input') {
            $this->timezone($text);
            return;
        }
        if (
            $step === 'field' &&
            ($this->s['field'] ?? '') === 'deadline' &&
            $action === 'today' &&
            $text[0] !== '/'
        ) {
            $this->field($text);
            return;
        }
        if ($action !== null) {
            $this->s = [];
            $this->navigate($action);
            return;
        }
        if ($text[0] === '/') {
            $this->say($this->l('Команды: /help. Отменить ввод: /cancel.', 'Commands: /help. Cancel input: /cancel.'));
            return;
        }
        if (mb_strlen($text) > 200) {
            $this->say(
                $this->l(
                    'Задание должно быть не длиннее 200 символов.',
                    'The task must be no longer than 200 characters.',
                ),
            );
            return;
        }
        if ($step === 'priority') {
            $this->priority($text);
            return;
        }
        if ($step === 'duration_custom') {
            $value = Dates::duration($text);
            if ($value === null) {
                $this->say($this->l('Укажи время, например: 30 минут или 1.5 часа.', 'Enter a duration, for example: 30 minutes or 1.5 hours.'));
                return;
            }
            $this->applyDuration($value, true);
            return;
        }
        if ($step === 'field') {
            $this->field($text);
            return;
        }
        if ($step !== '' && $step !== 'ai_input') {
            $this->say($this->l('Выбери действие кнопкой. /cancel - отмена.', 'Choose an action with a button. /cancel cancels.'));
            return;
        }
        if (!isset($prepared['drafts'])) {
            $this->s = ['step' => 'ai_input', 'provider' => true];
            $this->say($this->l(
                'GigaChat не смог обработать задание. Попробуй отправить его ещё раз или нажми «Отменить».',
                'GigaChat could not process the task. Send it again or tap Cancel.',
            ));
            return;
        }
        $drafts = $prepared['drafts'];
        if (!$drafts) {
            $example = Emojis::escape($this->taskExample());
            $this->say(
                $this->l(
                    'Не нашёл задач. Попробуй «' . $example . '» или /add.',
                    'No tasks found. Try "' . $example . '" or /add.',
                ),
            );
            return;
        }
        $this->s = [
            'drafts' => $drafts,
            'auto_save' => count($drafts) === 1,
            'source' => 'GigaChat',
        ];
        foreach ($this->s['drafts'] as &$draft) {
            $draft['importance'] = 2;
            $draft['duration_visible'] = empty($draft['estimated_minutes']) ? 0 : 1;
        }
        unset($draft);
        $this->fill();
    }

    private function timezone(string $text): void
    {
        $text = str_replace('.', ':', trim($text));
        if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~D', trim($text))) {
            $this->say($this->l(
                'Напишите текущее время в формате 14:30 или 14.30.',
                'Enter your current time as 14:30 or 14.30.',
            ));
            return;
        }
        [$hour, $minute] = array_map('intval', explode(':', trim($text)));
        $utc = $this->now->setTimezone(new \DateTimeZone('UTC'));
        $offset = $hour * 60 + $minute - ((int) $utc->format('H') * 60 + (int) $utc->format('i'));
        while ($offset < -720) {
            $offset += 1440;
        }
        while ($offset > 840) {
            $offset -= 1440;
        }
        $offset = (int) round($offset / 15) * 15;
        $sign = $offset < 0 ? '-' : '+';
        $absolute = abs($offset);
        $timezone = sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
        $this->db->run(
            'UPDATE users SET timezone=?,timezone_confirmed=1 WHERE telegram_id=?',
            [$timezone, $this->user],
        );
        $this->now = $this->now->setTimezone(new \DateTimeZone($timezone));
        $this->s = [];
        $utcOffset = 'UTC ' . $sign . intdiv($absolute, 60);
        if ($absolute % 60 !== 0) {
            $utcOffset .= ':' . str_pad((string) ($absolute % 60), 2, '0', STR_PAD_LEFT);
        }
        $this->say(
            $this->l(
                'Готово, у вас ' . $utcOffset . '. Добавьте первое задание.',
                'Ready, your time zone is ' . $utcOffset . '. Add your first task.',
            ),
            Emojis::inline([[[$this->l('➕ Добавить', '➕ Add'), 'nav:add']]]),
        );
    }

    private function navigate(string $action): void
    {
        if ($action === 'start') {
            $this->s = ['step' => 'onboarding', 'token' => $this->token()];
            $this->db->sendRich(
                $this->user,
                $this->welcomeMarkdown(),
                Emojis::inline([
                    [[
                        $this->l('Переключиться на английский (English)', 'Switch to Russian (Русский)'),
                        'onboard:' . $this->s['token'] . ':language',
                    ]],
                    [[$this->l('ОК', 'OK'), 'onboard:' . $this->s['token'] . ':continue']],
                ]),
            );
        } elseif ($action === 'cancel') {
            $this->say($this->l('Ввод отменён.', 'Input cancelled.'), $this->menu());
        } elseif ($action === 'help') {
            $this->s = ['step' => 'help', 'token' => $this->token()];
            $this->help('menu');
        } elseif ($action === 'add') {
            $this->db->run('UPDATE users SET ai_consent=1 WHERE telegram_id=?', [$this->user]);
            $this->s = ['step' => 'ai_input', 'provider' => true];
            $exampleText = $this->taskExample();
            $example = Emojis::escape($exampleText);
            $this->say(
                $this->l(
                    "Отправь задание одним сообщением, до 200 символов.\n\n<i>Например: $example (обработает GigaChat)</i>",
                    "Send a task in one message, up to 200 characters.\n\n<i>For example: $example (processed by GigaChat)</i>",
                ),
                Emojis::keyboard([
                    [$this->l('❌ Отмена', '❌ Cancel')],
                    [$exampleText],
                ]),
            );
        } elseif ($action === 'tasks' || $action === 'today') {
            $this->listing($action, 0);
        } elseif ($action === 'week') {
            $this->say(
                $this->l('Раздел «Нагрузка на неделю» удалён.', 'The weekly workload section has been removed.'),
                $this->menu(),
            );
        } elseif ($action === 'stats') {
            $this->statistics();
        } elseif ($action === 'settings') {
            $this->settings();
        } elseif ($action === 'ai') {
            $this->navigate('add');
        } elseif ($action === 'explain') {
            $this->s = ['step' => 'ai_choice', 'action' => $action, 'token' => $this->token()];
            $rows = [];
            $rows[] = [['✨ Объяснить', 'ai:' . $this->s['token'] . ':provider']];
            $this->say(
                $this->l('GigaChat объяснит, с чего лучше начать.', 'GigaChat will explain where to start.'),
                Emojis::inline($rows),
            );
        }
    }

    private function ask(string $field): void
    {
        $this->s['step'] = 'field';
        $this->s['field'] = $field;
        $prompts = $this->language === 'en' ? [
            'title' => 'What is the task called?',
            'subject' => 'Which subject? You can skip it.',
            'deadline' => 'When is it due? For example: tomorrow at 18:00 or 10.09.2026. Without a time, 23:59 is used.',
            'estimated_minutes' => 'How much time is needed? For example: 30 minutes or 1.5 hours.',
            'importance' => 'How important is the task?',
        ] : [
            'title' => 'Как называется задача?',
            'subject' => 'Какой предмет? Можно пропустить.',
            'deadline' =>
                'Когда сдать? Например: завтра в 18:00 или 10.09.2026. Без времени - 23:59.',
            'estimated_minutes' => 'Сколько времени нужно? Например: 30 минут или 1.5 часа.',
            'importance' => 'Насколько важна задача?',
        ];
        $text = $prompts[$field];
        $rows = [];
        if ($field === 'subject') {
            $rows[] = [$this->l('Пропустить', 'Skip')];
        }
        if ($field === 'importance') {
            $rows = [['🟢 Обычная'], ['🟡 Важная'], ['🔴 Очень важная']];
        }
        if (in_array($this->s['mode'] ?? '', ['edit', 'saved_edit'], true)) {
            $rows[] = [$this->l('Оставить', 'Keep')];
        }
        $rows[] = [$this->l('Отмена', 'Cancel')];
        if (count($this->s['drafts']) > 1) {
            $text =
                $this->l('Задача ', 'Task ') .
                ($this->s['index'] + 1) .
                '/' .
                count($this->s['drafts']) .
                "\n" .
                $text;
        }
        $this->say($text, Emojis::keyboard($rows));
    }

    private function field(string $text): void
    {
        $f = $this->s['field'];
        $i = $this->s['index'];
        $mode = $this->s['mode'];
        $draft = $this->s['drafts'][$i];
        $keep = in_array($text, ['Оставить', 'Keep'], true) && in_array($mode, ['edit', 'saved_edit'], true);
        if ($keep) {
            $value = $draft[$f] ?? null;
        } elseif ($f === 'title') {
            if (mb_strlen($text) > 200) {
                $this->say($this->l('Название: до 200 символов.', 'The title can contain up to 200 characters.'));
                return;
            }
            $value = $text;
        } elseif ($f === 'subject') {
            $value = in_array(mb_strtolower($text), ['пропустить', 'skip', '-'], true) ? null : $text;
            if ($value !== null && mb_strlen($value) > 100) {
                $this->say($this->l('Предмет: до 100 символов.', 'The subject can contain up to 100 characters.'));
                return;
            }
        } elseif ($f === 'deadline') {
            $value = Dates::parse($text, $this->now);
            if (!$value || strtotime($value) <= $this->now->getTimestamp()) {
                $this->say($this->l('Укажи будущую дату. Например: завтра в 18:00.', 'Enter a future date, for example: tomorrow at 18:00.'));
                return;
            }
        } elseif ($f === 'estimated_minutes') {
            $value = Dates::duration($text);
            if ($value === null) {
                $this->say($this->l('Укажи время от 1 до 10080 минут.', 'Enter a duration from 1 to 10080 minutes.'));
                return;
            }
        } else {
            $plain = trim(preg_replace('~^[^\p{L}\p{N}]+~u', '', $text));
            $value =
                ['Обычная' => 1, 'Важная' => 2, 'Очень важная' => 3, '1' => 1, '2' => 2, '3' => 3][
                    $plain
                ] ?? null;
            if ($value === null) {
                $this->say($this->l('Выбери важность кнопкой.', 'Choose the priority with a button.'));
                return;
            }
        }
        $this->s['drafts'][$i][$f] = $value;
        if ($f === 'estimated_minutes') {
            $this->s['drafts'][$i]['duration_visible'] = 1;
        }
        if ($f === 'importance') {
            $this->s['drafts'][$i]['importance_visible'] = 1;
        }
        if ($mode === 'saved_edit') {
            if ($keep) {
                $this->s = [];
                $this->say($this->l('Без изменений.', 'No changes.'), $this->menu());
                return;
            }
            $this->s['step'] = 'edit_confirm';
            $this->s['token'] = $this->token();
            $this->say(
                Planner::card($this->s['drafts'][0], $this->now, $this->language) . "\n\n" . $this->l('Сохранить?', 'Save changes?'),
                Emojis::inline([
                    [
                        [$this->l('✅ Сохранить', '✅ Save'), 'editconfirm:' . $this->s['token'] . ':save'],
                        [$this->l('Отмена', 'Cancel'), 'editconfirm:' . $this->s['token'] . ':cancel'],
                    ],
                ]),
            );
            return;
        }
        if ($mode === 'missing') {
            $this->fill();
            return;
        }
        $next = array_search($f, self::FIELDS, true) + 1;
        if ($next < count(self::FIELDS)) {
            $this->ask(self::FIELDS[$next]);
            return;
        }
        if ($mode === 'manual') {
            try {
                $this->db->create($this->user, $this->s['drafts'][0], $this->now);
            } catch (\InvalidArgumentException $e) {
                $this->say($e->getMessage());
                $this->ask('deadline');
                return;
            }
            $this->s = [];
            $this->say($this->l('✅ Задача добавлена.', '✅ Task added.'), $this->menu());
        } else {
            $this->fill();
        }
    }

    private function preview(): void
    {
        $this->s['step'] = 'review';
        $this->s['token'] = $this->token();
        $this->say($this->l('Нашёл несколько задач:', 'Several tasks found:'), $this->menu());
        foreach ($this->s['drafts'] as $i => $t) {
            $this->say($i + 1 . '. ' . Planner::card($t, $this->now, $this->language));
        }
        $token = $this->s['token'];
        $this->say(
            $this->l('Добавить найденные задачи?', 'Add the tasks found?'),
            Emojis::inline([
                [
                    [$this->l('✅ Добавить', '✅ Add'), 'review:' . $token . ':add'],
                    [$this->l('❌ Отмена', '❌ Cancel'), 'review:' . $token . ':cancel'],
                ],
            ]),
        );
    }

    private function fill(): void
    {
        foreach ($this->s['drafts'] as $i => $draft) {
            foreach (['deadline'] as $field) {
                if (
                    !isset($draft[$field]) ||
                    ($field === 'deadline' &&
                        strtotime($draft[$field]) <= $this->now->getTimestamp())
                ) {
                    $this->s['drafts'][$i][$field] = null;
                    $this->s['index'] = $i;
                    $this->s['mode'] = 'missing';
                    $this->ask($field);
                    return;
                }
            }
        }
        foreach ($this->s['drafts'] as $i => $draft) {
            if (empty($draft['estimated_minutes'])) {
                $this->s['duration_index'] = $i;
                $this->askDuration();
                return;
            }
        }
        if (empty($this->s['priority_selected'])) {
            $this->s['priority_index'] = 0;
            $this->askPriority();
            return;
        }
        if (!empty($this->s['auto_save']) && count($this->s['drafts']) === 1) {
            $draft = $this->s['drafts'][0];
            Dates::validate($draft, $this->now);
            $this->db->create($this->user, $draft, $this->now);
            $this->s = [];
            $this->say(
                $this->l('✅ <b>Задание добавлено</b>', '✅ <b>Task added</b>') . "\n\n" .
                    Planner::card($draft, $this->now, $this->language) .
                    "\n\n<code>" . $this->l('Мы напомним вам об этом', 'We will remind you about it') . '</code>',
                $this->menu(),
            );
            return;
        }
        $this->preview();
    }

    private function askDuration(): void
    {
        $index = (int) $this->s['duration_index'];
        $this->s['step'] = 'duration';
        $this->s['token'] = $this->token();
        $text = $this->l('Сколько времени займёт задача?', 'How long will the task take?');
        if (count($this->s['drafts']) > 1) {
            $text .= "\n" . ($index + 1) . '/' . count($this->s['drafts']) . ' - ' . Emojis::escape($this->s['drafts'][$index]['title']);
        }
        $this->say($text, Emojis::inline([
            [
                [$this->l('До 10 минут', 'Up to 10 min'), 'duration:' . $this->s['token'] . ':10'],
                [$this->l('До 60 минут', 'Up to 60 min'), 'duration:' . $this->s['token'] . ':60'],
            ],
            [
                [$this->l('Больше 120 минут', 'More than 120 min'), 'duration:' . $this->s['token'] . ':180'],
                [$this->l('Указать своё', 'Enter my own'), 'duration:' . $this->s['token'] . ':custom'],
            ],
            [[
                $this->l('Не указывать', 'Do not show'),
                'duration:' . $this->s['token'] . ':skip',
            ]],
        ]));
    }

    private function applyDuration(int $minutes, bool $visible): void
    {
        $index = (int) $this->s['duration_index'];
        $this->s['drafts'][$index]['estimated_minutes'] = $minutes;
        $this->s['drafts'][$index]['duration_visible'] = (int) $visible;
        unset($this->s['duration_index']);
        $this->fill();
    }

    private function askPriority(): void
    {
        $index = (int) ($this->s['priority_index'] ?? 0);
        $this->s['step'] = 'priority';
        $this->s['token'] = $this->token();
        $text = $this->l('Какая важность у задачи?', 'How important is the task?');
        if (count($this->s['drafts']) > 1) {
            $text .=
                "\n" .
                ($index + 1) .
                '/' .
                count($this->s['drafts']) .
                ' - ' .
                Emojis::escape($this->s['drafts'][$index]['title']);
        }
        $this->say(
            $text,
            Emojis::inline([
                [
                    [$this->l('Низкий', 'Low'), 'priority:' . $this->s['token'] . ':low'],
                    [$this->l('Средний', 'Medium'), 'priority:' . $this->s['token'] . ':medium'],
                    [$this->l('Высокий', 'High'), 'priority:' . $this->s['token'] . ':high'],
                ],
                [
                    [$this->l('Пропустить', 'Skip'), 'priority:' . $this->s['token'] . ':skip'],
                    [$this->l('Отмена', 'Cancel'), 'priority:' . $this->s['token'] . ':cancel'],
                ],
            ]),
        );
    }

    private function priority(string $text): void
    {
        $plain = trim(preg_replace('~^[^\p{L}\p{N}]+~u', '', $text));
        $values = ['Низкий' => 1, 'Средний' => 2, 'Высокий' => 3, 'Пропустить' => 2, 'Low' => 1, 'Medium' => 2, 'High' => 3, 'Skip' => 2];
        if (!isset($values[$plain])) {
            $this->say($this->l('Выбери приоритет кнопкой или нажми «Пропустить».', 'Choose a priority or tap "Skip".'));
            return;
        }
        if ($this->incomingMessageId !== null) {
            $this->db->out('deleteMessage', [
                'chat_id' => $this->user,
                'message_id' => $this->incomingMessageId,
            ]);
        }
        $this->applyPriority($values[$plain], !in_array($plain, ['Пропустить', 'Skip'], true));
    }

    private function applyPriority(int $value, bool $visible): void
    {
        $index = (int) ($this->s['priority_index'] ?? 0);
        $this->s['drafts'][$index]['importance'] = $value;
        $this->s['drafts'][$index]['importance_visible'] = (int) $visible;
        $index++;
        if ($index < count($this->s['drafts'])) {
            $this->s['priority_index'] = $index;
            $this->askPriority();
            return;
        }
        $this->s['priority_selected'] = true;
        unset($this->s['priority_index']);
        $this->fill();
    }

    private function callback(string $data, array $prepared): void
    {
        $p = explode(':', $data);
        $kind = $p[0];
        if (
            $kind === 'onboard' &&
            count($p) === 3 &&
            ($this->s['step'] ?? '') === 'onboarding' &&
            isset($this->s['token']) &&
            hash_equals($this->s['token'], $p[1]) &&
            $p[2] === 'language'
        ) {
            $this->language = $this->language === 'en' ? 'ru' : 'en';
            $this->db->run('UPDATE users SET language=? WHERE telegram_id=?', [
                $this->language,
                $this->user,
            ]);
            $payload = [
                'chat_id' => $this->user,
                'message_id' => $this->editMessageId,
                'rich_message' => ['markdown' => $this->welcomeMarkdown()],
                'reply_markup' => Emojis::inline([
                    [[
                        $this->l('Переключиться на английский (English)', 'Switch to Russian (Русский)'),
                        'onboard:' . $this->s['token'] . ':language',
                    ]],
                    [[$this->l('ОК', 'OK'), 'onboard:' . $this->s['token'] . ':continue']],
                ]),
            ];
            $this->db->out('editMessageText', $payload);
            $this->editMessageId = null;
            $this->editHasPhoto = false;
            return;
        }
        if (
            $kind === 'onboard' &&
            count($p) === 3 &&
            ($this->s['step'] ?? '') === 'onboarding' &&
            isset($this->s['token']) &&
            hash_equals($this->s['token'], $p[1]) &&
            $p[2] === 'continue'
        ) {
            if ($this->editMessageId !== null) {
                $this->db->out('deleteMessage', [
                    'chat_id' => $this->user,
                    'message_id' => $this->editMessageId,
                ]);
                $this->editMessageId = null;
                $this->editHasPhoto = false;
            }
            $this->db->run('UPDATE users SET ai_consent=1 WHERE telegram_id=?', [$this->user]);
            $this->s = ['step' => 'timezone_input'];
            $timeExample = '14:' . $this->now->format('i');
            $this->say($this->l(
                "<b>Сколько у вас сейчас времени?</b>\nОпределим часовой пояс для уведомлений о ваших задачах.\n\n<i>Например: $timeExample</i>",
                "<b>What time is it for you now?</b>\nWe'll determine your time zone for task notifications.\n\n<i>For example: $timeExample</i>",
            ));
            return;
        }
        if (
            $kind === 'nav' &&
            count($p) === 2 &&
            in_array(
                $p[1],
                ['today', 'tasks', 'add', 'stats', 'settings', 'ai', 'explain'],
                true,
            )
        ) {
            if ($p[1] === 'add' && $this->editMessageId !== null) {
                $this->db->out('deleteMessage', [
                    'chat_id' => $this->user,
                    'message_id' => $this->editMessageId,
                ]);
                $this->editMessageId = null;
            }
            $this->s = [];
            $this->navigate($p[1]);
            return;
        }
        if (
            $kind === 'page' &&
            count($p) === 3 &&
            in_array($p[1], ['today', 'tasks', 'due'], true) &&
            ctype_digit($p[2]) &&
            strlen($p[2]) <= 8
        ) {
            $this->s = [];
            $this->listing($p[1], (int) $p[2]);
            return;
        }
        if ($kind === 'task' && count($p) === 3 && ctype_digit($p[2]) && strlen($p[2]) <= 18) {
            $this->taskAction($p[1], (int) $p[2]);
            return;
        }
        if (count($p) < 3 || !isset($this->s['token']) || !hash_equals($this->s['token'], $p[1])) {
            $this->dismissStaleButton();
            return;
        }
        $step = $this->s['step'] ?? '';
        $a = $p[2];
        if ($kind === 'duration' && $step === 'duration' && in_array($a, ['10', '60', '180', 'custom', 'skip'], true)) {
            if ($this->editMessageId !== null) {
                $this->db->out('deleteMessage', ['chat_id' => $this->user, 'message_id' => $this->editMessageId]);
                $this->editMessageId = null;
            }
            if ($a === 'custom') {
                $this->s['step'] = 'duration_custom';
                $this->say(
                    $this->l('Сколько времени нужно? Например: 30 минут или 1.5 часа.', 'How much time is needed? For example: 30 minutes or 1.5 hours.'),
                    Emojis::keyboard([[$this->l('Отмена', 'Cancel')]]),
                );
                return;
            }
            $this->applyDuration($a === 'skip' ? 60 : (int) $a, $a !== 'skip');
        } elseif (
            $kind === 'priority' &&
            $step === 'priority' &&
            in_array($a, ['low', 'medium', 'high', 'skip', 'cancel'], true)
        ) {
            if ($this->editMessageId !== null) {
                $this->db->out('deleteMessage', [
                    'chat_id' => $this->user,
                    'message_id' => $this->editMessageId,
                ]);
                $this->editMessageId = null;
            }
            if ($a === 'cancel') {
                $this->s = [];
                $this->say($this->l('Добавление отменено.', 'Adding cancelled.'), $this->menu());
                return;
            }
            $values = ['low' => 1, 'medium' => 2, 'high' => 3, 'skip' => 2];
            $this->applyPriority($values[$a], $a !== 'skip');
        } elseif ($kind === 'review' && $step === 'review') {
            if ($a === 'cancel') {
                $this->s = [];
                $this->say($this->l('Добавление отменено.', 'Adding cancelled.'), $this->menu());
            } elseif ($a === 'edit' && count($p) === 3) {
                $rows = [];
                foreach ($this->s['drafts'] as $i => $t) {
                    $rows[] = [
                        [
                            '✏ ' . ($i + 1) . '. ' . mb_substr($t['title'], 0, 35),
                            'review:' . $p[1] . ':edit:' . $i,
                        ],
                    ];
                }
                $this->say($this->l('Какую задачу изменить?', 'Which task would you like to edit?'), Emojis::inline($rows));
            } elseif (
                $a === 'edit' &&
                count($p) === 4 &&
                ctype_digit($p[3]) &&
                isset($this->s['drafts'][(int) $p[3]])
            ) {
                $this->s['index'] = (int) $p[3];
                $this->s['mode'] = 'edit';
                $this->ask('title');
            } elseif ($a === 'add') {
                foreach ($this->s['drafts'] as $d) {
                    if (
                        empty($d['deadline']) ||
                        empty($d['estimated_minutes']) ||
                        strtotime($d['deadline']) <= $this->now->getTimestamp()
                    ) {
                        $this->fill();
                        return;
                    }
                    Dates::validate($d, $this->now);
                }
                foreach ($this->s['drafts'] as $d) {
                    $this->db->create($this->user, $d, $this->now);
                }
                $n = count($this->s['drafts']);
                $this->s = [];
                $this->say($this->l('✅ Добавлено задач: ', '✅ Tasks added: ') . $n . '.', $this->menu());
            }
        } elseif ($kind === 'delete' && $step === 'delete' && in_array($a, ['yes', 'no'], true)) {
            if ($a === 'yes') {
                $this->db->run(
                    "DELETE FROM tasks WHERE user_id=? AND id=? AND revision=? AND status='active'",
                    [$this->user, $this->s['task_id'], $this->s['revision']],
                );
            }
            $this->s = [];
            $this->listing('tasks', 0);
        } elseif (
            $kind === 'editfield' &&
            $step === 'edit_select' &&
            in_array($a, self::FIELDS, true)
        ) {
            $this->s['mode'] = 'saved_edit';
            $this->s['index'] = 0;
            $this->ask($a);
        } elseif (
            $kind === 'editconfirm' &&
            in_array($step, ['edit_select', 'edit_confirm'], true)
        ) {
            if ($a === 'cancel') {
                $this->s = [];
                $this->say($this->l('Изменения отменены.', 'Changes cancelled.'), $this->menu());
            } elseif ($a === 'save' && $step === 'edit_confirm') {
                $field = $this->s['field'];
                $draft = $this->s['drafts'][0];

                $check = $draft;
                if ($field !== 'deadline') {
                    $check['deadline'] = Dates::utc($this->now->modify('+1 day'));
                }
                try {
                    Dates::validate($check, $this->now);
                } catch (\InvalidArgumentException $e) {
                    $this->say($e->getMessage());
                    $this->ask('deadline');
                    return;
                }
                $reset = $field === 'deadline'
                    ? ',reminder_24h_sent=0,reminder_3h_sent=0,overdue_sent=0'
                    : '';
                $visible = $field === 'importance'
                    ? ',importance_visible=1'
                    : ($field === 'estimated_minutes' ? ',duration_visible=1' : '');
                $changed = $this->db
                    ->run(
                        "UPDATE tasks SET $field=?,revision=revision+1$reset$visible WHERE user_id=? AND id=? AND revision=? AND status='active'",
                        [
                            $draft[$field] ?? null,
                            $this->user,
                            $this->s['task_id'],
                            $this->s['revision'],
                        ],
                    )
                    ->rowCount();
                $this->s = [];
                $this->say(
                    $changed
                        ? $this->l('✅ Изменение сохранено.', '✅ Changes saved.')
                        : $this->l('Задача уже изменена. Открой /tasks.', 'The task has already changed. Open /tasks.'),
                    $this->menu(),
                );
            }
        } elseif (
            $kind === 'help' &&
            $step === 'help' &&
            in_array($a, ['menu', 'add', 'tasks', 'reminders', 'gigachat'], true)
        ) {
            $this->help($a);
        } elseif ($kind === 'settings' && $step === 'settings') {
            if ($a === 'reminders') {
                $this->db->run('UPDATE users SET reminders_disabled=1-reminders_disabled WHERE telegram_id=?', [$this->user]);
                $this->settings();
            } elseif ($a === 'calendar') {
                $key = 'calendar_disabled_' . $this->user;
                if ($this->db->getCache($key)) {
                    $this->db->deleteCache($key);
                } else {
                    $this->db->setCache($key, '1');
                }
                $this->settings();
            } elseif ($a === 'language') {
                $this->languageSettings();
            } elseif ($a === 'delete_data') {
                $this->say(
                    $this->l('Удалить все ваши данные? Это действие нельзя отменить.', 'Delete all your data? This action cannot be undone.'),
                    Emojis::inline([[
                        [$this->l('Удалить данные', 'Delete data'), 'settings:' . $this->s['token'] . ':delete_data_yes'],
                        [$this->l('Отмена', 'Cancel'), 'settings:' . $this->s['token'] . ':delete_data_cancel'],
                    ]]),
                );
            } elseif ($a === 'delete_data_yes') {
                $messageIds = array_filter([
                    $this->editMessageId,
                    $this->s['request_message_id'] ?? null,
                ], 'is_int');
                $this->db->deleteUserData($this->user);
                $this->s = [];
                $this->profileDeleted = true;
                foreach (array_unique($messageIds) as $messageId) {
                    $this->db->out('deleteMessage', [
                        'chat_id' => $this->user,
                        'message_id' => $messageId,
                    ]);
                }
                $this->editMessageId = null;
                $this->editHasPhoto = false;
                $this->say(
                    $this->l('Ваши данные удалены.', 'Your data has been deleted.'),
                    ['remove_keyboard' => true],
                );
            } elseif ($a === 'delete_data_cancel') {
                $this->settings();
            } elseif ($a === 'close') {
                $messageIds = array_filter([
                    $this->editMessageId,
                    $this->s['request_message_id'] ?? null,
                ], 'is_int');
                $this->s = [];
                foreach (array_unique($messageIds) as $messageId) {
                    $this->db->out('deleteMessage', [
                        'chat_id' => $this->user,
                        'message_id' => $messageId,
                    ]);
                }
                $this->editMessageId = null;
                $this->editHasPhoto = false;
            } elseif ($a === 'back') {
                $this->settings();
            } elseif (in_array($a, ['ru', 'en'], true)) {
                $this->db->run('UPDATE users SET language=? WHERE telegram_id=?', [$a, $this->user]);
                $this->language = $a;
                if ($this->editMessageId !== null) {
                    $this->db->out('deleteMessage', [
                        'chat_id' => $this->user,
                        'message_id' => $this->editMessageId,
                    ]);
                }
                $this->editMessageId = null;
                $this->editHasPhoto = false;
                $this->s = [];
                $this->say(
                    $this->l('Язык изменён на русский.', 'Language changed to English.'),
                    $this->menu(),
                );
            } else {
                $this->dismissStaleButton();
            }
        } elseif (
            $kind === 'ai' &&
            $step === 'ai_choice' &&
            in_array($a, ['provider', 'local'], true)
        ) {
            $provider = $a === 'provider' && $this->ai->available();
            $action = $this->s['action'];
            if ($provider) {
                $this->db->run('UPDATE users SET ai_consent=1 WHERE telegram_id=?', [$this->user]);
            }
            $this->s = [];
            if ($action === 'explain') {
                $this->explain($provider ? $prepared : [], $provider);
            } else {
                $this->s = ['step' => 'ai_input', 'provider' => $provider];
                $this->say(
                    $this->l('Отправь задание: до 200 символов. Сохраню после подтверждения.', 'Send a task in up to 200 characters. It will be saved after confirmation.'),
                    $this->menu(),
                );
            }
        } else {
            $this->dismissStaleButton();
        }
    }

    private function taskAction(string $action, int $id): void
    {
        if (!in_array($action, ['detail', 'done', 'delete', 'edit', 'reschedule'], true)) {
            $this->say($this->l('Открой /tasks.', 'Open /tasks.'));
            return;
        }
        $t = $this->db->task($this->user, $id);
        if (!$t) {
            $this->say($this->l('Задача уже завершена или удалена.', 'The task has already been completed or deleted.'));
            return;
        }
        $this->s = [];
        if ($action === 'detail') {
            $this->say(
                Planner::card($t, $this->now, $this->language),
                Emojis::inline([
                    [[$this->l('✅ Выполнено', '✅ Complete'), 'task:done:' . $id], [$this->l('✏ Изменить', '✏ Edit'), 'task:edit:' . $id]],
                    [[$this->l('❌ Удалить', '❌ Delete'), 'task:delete:' . $id]],
                    [[$this->l('⬅ Назад к списку', '⬅ Back to tasks'), 'page:tasks:0']],
                ]),
            );
        } elseif ($action === 'done') {
            $this->db->run(
                "UPDATE tasks SET status='completed',completed_at=?,revision=revision+1 WHERE user_id=? AND id=? AND status='active'",
                [Dates::utc($this->now), $this->user, $id],
            );
            $this->listing('today', 0);
        } elseif ($action === 'reschedule') {
            $this->s = [
                'step' => 'edit_select',
                'token' => $this->token(),
                'task_id' => $id,
                'revision' => (int) $t['revision'],
                'drafts' => [array_intersect_key($t, array_flip(self::FIELDS))],
                'mode' => 'saved_edit',
                'index' => 0,
            ];
            if ($this->editMessageId !== null) {
                $this->db->out('deleteMessage', [
                    'chat_id' => $this->user,
                    'message_id' => $this->editMessageId,
                ]);
                $this->editMessageId = null;
            }
            $this->ask('deadline');
        } elseif ($action === 'delete') {
            $this->s = [
                'step' => 'delete',
                'token' => $this->token(),
                'task_id' => $id,
                'revision' => (int) $t['revision'],
            ];
            $this->say(
                $this->l('Удалить «', 'Delete "') . Emojis::escape($t['title']) . $this->l('»?', '"?'),
                Emojis::inline([
                    [
                        [$this->l('Удалить', 'Delete'), 'delete:' . $this->s['token'] . ':yes'],
                        [$this->l('Отмена', 'Cancel'), 'delete:' . $this->s['token'] . ':no'],
                    ],
                ]),
            );
        } else {
            $this->s = [
                'step' => 'edit_select',
                'token' => $this->token(),
                'task_id' => $id,
                'revision' => (int) $t['revision'],
                'drafts' => [array_intersect_key($t, array_flip(self::FIELDS))],
            ];
            $this->s['drafts'][0]['estimated_minutes'] = (int) $t['estimated_minutes'];
            $this->s['drafts'][0]['importance'] = (int) $t['importance'];
            $rows = [];
            foreach (self::LABELS as $field => $label) {
                $english = ['title' => 'Title', 'subject' => 'Subject', 'deadline' => 'Deadline', 'estimated_minutes' => 'Duration'];
                $rows[] = [[$this->language === 'en' ? $english[$field] : $label, 'editfield:' . $this->s['token'] . ':' . $field]];
            }
            $rows[] = [[$this->l('Отмена', 'Cancel'), 'editconfirm:' . $this->s['token'] . ':cancel']];
            $this->say(Planner::card($t, $this->now, $this->language) . "\n\n" . $this->l('Что изменить?', 'What would you like to change?'), Emojis::inline($rows));
        }
    }

    private function listing(string $mode, int $page): void
    {
        $imagesEnabled = !$this->db->getCache('calendar_disabled_' . $this->user);
        $tasks = $this->db->tasks($this->user);
        if ($mode !== 'tasks') {
            $tasks = Planner::plan($tasks, $this->now);
        } else {
            $timezone = $this->now->getTimezone();
            usort($tasks, function (array $a, array $b) use ($timezone): int {
                $aDeadline = (new \DateTimeImmutable($a['deadline']))->setTimezone($timezone);
                $bDeadline = (new \DateTimeImmutable($b['deadline']))->setTimezone($timezone);
                return [
                    $aDeadline->format('Y-m-d'),
                    -(!empty($a['importance_visible']) ? (int) $a['importance'] : 0),
                    $aDeadline->getTimestamp(),
                    (int) $a['id'],
                ] <=> [
                    $bDeadline->format('Y-m-d'),
                    -(!empty($b['importance_visible']) ? (int) $b['importance'] : 0),
                    $bDeadline->getTimestamp(),
                    (int) $b['id'],
                ];
            });
        }
        if ($mode === 'today') {
            $today = $this->now->format('Y-m-d');
            $timezone = $this->now->getTimezone();
            $tasks = array_values(
                array_filter($tasks, function ($task) use ($today, $timezone) {
                    return (new \DateTimeImmutable($task['deadline']))
                            ->setTimezone($timezone)
                            ->format('Y-m-d') === $today;
                }),
            );
        }
        if ($mode === 'due') {
            $tasks = array_values(
                array_filter($tasks, function ($t) {
                    return strtotime($t['deadline']) <= $this->now->getTimestamp();
                }),
            );
        }
        if (!$tasks) {
            if ($mode === 'tasks' && $imagesEnabled) {
                $payload = [
                    'chat_id' => $this->user,
                    'month' => $this->now->format('Y-m'),
                    'text' => $mode === 'today'
                        ? $this->l('На сегодня задач нет.', 'No tasks for today.')
                        : $this->l('Активных задач нет. Добавить: /add.', 'No active tasks. Add one: /add.'),
                    'reply_markup' => Emojis::inline([[[$this->l('Добавить задание', 'Add task'), 'nav:add']]]),
                ];
                if ($this->editMessageId !== null) {
                    $payload['message_id'] = $this->editMessageId;
                    $this->editMessageId = null;
                }
                $this->db->out('sendTaskCalendar', $payload);
                return;
            }
            $this->say(
                $mode === 'today'
                    ? $this->l('На сегодня задач нет.', 'No tasks for today.')
                    : $this->l('Активных задач нет. Добавить: /add.', 'No active tasks. Add one: /add.'),
                $this->menu(),
            );
            return;
        }
        $pages = (int) ceil(count($tasks) / 5);
        $page = min(max(0, $page), $pages - 1);
        $text = $mode === 'tasks'
            ? $this->l('📖 Все задачи', '📖 All tasks')
            : $this->l('📚 <b>План на сегодня</b>', '📚 <b>Today plan</b>');
        if ($pages > 1) {
            $text .= ' · ' . ($page + 1) . '/' . $pages;
        }
        if ($mode === 'today' && count($tasks) > 1) {
            $text .= "\n" . $this->l('Всего на сегодня: ', 'Total for today: ') . array_sum(array_column($tasks, 'minutes')) . $this->l(' мин.', ' min.');
        }
        $rows = [];
        $taskButtons = [];
        $lastDate = null;
        foreach (array_slice($tasks, $page * 5, 5) as $i => $t) {
            if ($mode === 'tasks') {
                $deadline = (new \DateTimeImmutable($t['deadline']))->setTimezone($this->now->getTimezone());
                $date = $deadline->format('d.m.Y');
                if ($date !== $lastDate) {
                    if ($lastDate !== null) {
                        $text .= "\n\n━━━━━━━━━━━━";
                    }
                    $text .= "\n\n📅 $date";
                    $lastDate = $date;
                }
                $priority = $this->language === 'en'
                    ? ['', '🟢 Low', '🟡 Medium', '🔴 High']
                    : ['', '🟢 Низкий', '🟡 Средний', '🔴 Высокий'];
                $number = $page * 5 + $i + 1;
                $text .= "\n\n#$number. " . Emojis::escape($t['title']);
                if ($deadline->format('H:i') !== '23:59') {
                    $text .= "\n🕒 " . $deadline->format('H:i');
                }
                $details = [];
                if (!isset($t['duration_visible']) || !empty($t['duration_visible'])) {
                    $details[] = '⏱ ' . $t['estimated_minutes'] . $this->l(' мин', ' min');
                }
                if (!empty($t['importance_visible'])) {
                    $details[] = $priority[(int) $t['importance']];
                }
                if ($details) {
                    $text .= "\n" . implode(' · ', $details);
                }
            } else {
                $text .= "\n\n" . ($page * 5 + $i + 1) . '. ' . Planner::card($t, $this->now, $this->language);
            }
            if ($mode === 'tasks' || count($tasks) > 1) {
                $taskButtons[] = [
                    '🖌 #' . ($page * 5 + $i + 1),
                    'task:detail:' . $t['id'],
                ];
            }
        }
        foreach (array_chunk($taskButtons, 5) as $buttonRow) {
            $rows[] = $buttonRow;
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = [$this->l('⬅ Назад', '⬅ Back'), 'page:' . $mode . ':' . ($page - 1)];
        }
        if ($page + 1 < $pages) {
            $nav[] = [$this->l('➡ Далее', '➡ Next'), 'page:' . $mode . ':' . ($page + 1)];
        }
        if ($nav) {
            $rows[] = $nav;
        }

        $buttons = [];
        foreach ($rows as $r) {
            $buttons[] = isset($r[0]) && is_string($r[0]) ? [$r] : $r;
        }
        if ($mode === 'tasks' && $imagesEnabled) {
            $calendarDate = $mode === 'today' ? $this->now : (new \DateTimeImmutable($tasks[$page * 5]['deadline']))
                ->setTimezone($this->now->getTimezone());
            $payload = [
                'chat_id' => $this->user,
                'month' => $calendarDate->format('Y-m'),
                'text' => Emojis::render($text),
                'reply_markup' => Emojis::inline($buttons),
            ];
            if ($this->editMessageId !== null) {
                $payload['message_id'] = $this->editMessageId;
                $this->editMessageId = null;
            }
            $this->db->out('sendTaskCalendar', $payload);
            return;
        }
        $this->say($text, Emojis::inline($buttons));
    }

    private function statistics(): void
    {
        $done = 0;
        $on = 0;
        $active = 0;
        $overdue = 0;
        foreach ($this->db->tasks($this->user, false) as $t) {
            if ($t['status'] === 'completed') {
                $done++;
                if (
                    $t['completed_at'] &&
                    strtotime($t['completed_at']) <= strtotime($t['deadline'])
                ) {
                    $on++;
                }
            } else {
                $active++;
                if (strtotime($t['deadline']) <= $this->now->getTimestamp()) {
                    $overdue++;
                }
            }
        }
        $late = $done - $on;
        $percent = $done ? round((100 * $on) / $done) . '%' : '-';
        $icon = '![📊](tg://emoji?id=' . Emojis::MAP['📊'][0] . ')';
        $markdown = $this->language === 'en'
            ? "## $icon Statistics\n\n| Metric | Value |\n|:--|--:|\n| Active | $active |\n| Overdue | $overdue |\n| Completed | $done |\n| On time | $on |\n| Late | $late |\n| On-time rate | $percent |"
            : "## $icon Статистика\n\n| Показатель | Значение |\n|:--|--:|\n| Активно | $active |\n| Просрочено | $overdue |\n| Выполнено | $done |\n| Вовремя | $on |\n| С опозданием | $late |\n| Доля вовремя | $percent |";
        $this->db->sendRich($this->user, $markdown, $this->menu());
    }

    private function help(string $section): void
    {
        $token = $this->s['token'];
        if ($section === 'menu') {
            $this->say(
                $this->l("❓ <b>Помощь</b>\nВыберите раздел:", "❓ <b>Help</b>\nChoose a section:"),
                Emojis::inline([
                    [
                        [$this->l('➕ Добавление', '➕ Adding tasks'), 'help:' . $token . ':add'],
                        [$this->l('📚 Задания', '📚 Tasks'), 'help:' . $token . ':tasks'],
                    ],
                    [
                        [$this->l('⏰ Напоминания', '⏰ Reminders'), 'help:' . $token . ':reminders'],
                        ['✨ GigaChat', 'help:' . $token . ':gigachat'],
                    ],
                ]),
            );
            return;
        }
        $texts = $this->language === 'en' ? [
            'add' => "➕ <b>Adding a task</b>\nTap " . '"Add task"' . ' and send the task in one message. One task is saved immediately; multiple tasks can be reviewed before saving.',
            'tasks' => "📚 <b>Working with tasks</b>\nOpen " . '"All tasks"' . ' and select a task to complete, edit, or delete it.',
            'reminders' => "⏰ <b>Reminders</b>\nThe bot reminds you as the deadline approaches. You can turn reminders off in settings.",
            'gigachat' => "✨ <b>GigaChat</b>\nGigaChat finds tasks in your text, improves their names, and helps you decide where to start.",
        ] : [
            'add' =>
                "➕ <b>Добавление задания</b>\nНажмите «Добавить задание» и отправьте текст одним сообщением. Одно задание сохранится сразу, несколько можно подтвердить списком.",
            'tasks' =>
                "📚 <b>Работа с заданиями</b>\nОткройте «Все задачи», выберите задание и отметьте его выполненным, измените или удалите.",
            'reminders' =>
                "⏰ <b>Напоминания</b>\nБот напомнит о приближении дедлайна. Напоминания можно выключить в настройках.",
            'gigachat' =>
                "✨ <b>GigaChat</b>\nGigaChat выделяет задания из текста, уточняет названия и помогает выбрать, с чего начать.",
        ];
        $this->say(
            $texts[$section],
            Emojis::inline([[[$this->l('⬅ Назад', '⬅ Back'), 'help:' . $token . ':menu']]]),
        );
    }

    private function settings(): void
    {
        $u = $this->db->one('SELECT * FROM users WHERE telegram_id=?', [$this->user]);
        $requestMessageId = $this->s['request_message_id'] ?? $this->incomingMessageId;
        $this->s = ['step' => 'settings', 'token' => $this->token()];
        if (is_int($requestMessageId)) {
            $this->s['request_message_id'] = $requestMessageId;
        }
        $rows = [
            [[
                $this->db->getCache('calendar_disabled_' . $this->user)
                    ? $this->l('Включить картинки календаря', 'Enable calendar images')
                    : $this->l('Выключить картинки календаря', 'Disable calendar images'),
                'settings:' . $this->s['token'] . ':calendar',
            ]],
            [
                [
                    $u['reminders_disabled']
                        ? $this->l('Включить напоминания', 'Turn reminders on')
                        : $this->l('Выключить напоминания', 'Turn reminders off'),
                    'settings:' . $this->s['token'] . ':reminders',
                ],
            ],
            [
                [
                    $this->l('Язык: Русский', 'Language: English'),
                    'settings:' . $this->s['token'] . ':language',
                ],
            ],
            [
                [
                    $this->l('Удалить данные', 'Delete data'),
                    'settings:' . $this->s['token'] . ':delete_data',
                ],
            ],
            [
                [
                    $this->l('Закрыть', 'Close'),
                    'settings:' . $this->s['token'] . ':close',
                ],
            ],
        ];
        $this->say(
            $this->l('⚙ <b>Настройки</b>', '⚙ <b>Settings</b>') .
                "\n" .
                $this->l('Напоминания: ', 'Reminders: ') .
                ($u['reminders_disabled']
                    ? $this->l('выключены', 'off')
                    : $this->l('включены', 'on')) .
                ".\n" .
                $this->l('Язык: Русский.', 'Language: English.'),
            Emojis::inline($rows),
        );
    }

    private function languageSettings(): void
    {
        $this->say(
            $this->l('Выберите язык.', 'Choose a language.'),
            Emojis::inline([
                [
                    ['Русский', 'settings:' . $this->s['token'] . ':ru'],
                    ['English', 'settings:' . $this->s['token'] . ':en'],
                ],
                [[$this->l('Назад', 'Back'), 'settings:' . $this->s['token'] . ':back']],
            ]),
        );
    }

    private function explain(array $prepared, bool $provider): void
    {
        $plan = Planner::plan($this->db->tasks($this->user), $this->now);
        if (!$plan) {
            $this->say($this->l('Задач нет. Добавить: /add.', 'No tasks. Add one: /add.'));
            return;
        }
        $text = $this->l('✨ <b>С чего начать</b>', '✨ <b>Where to start</b>');
        $reasonClauses = $this->language === 'en' ? [
            'deadline' => 'it has the closest deadline',
            'prepare' => 'this will spread out the workload',
            'importance' => 'it has high importance',
            'volume' => 'it will take more time',
        ] : [
            'deadline' => 'у неё ближайший срок',
            'prepare' => 'так получится спокойнее распределить нагрузку',
            'importance' => 'у неё высокая важность',
            'volume' => 'она потребует больше времени',
        ];
        $fallbackLeads = $this->language === 'en'
            ? [
                'First, I would handle this task',
                'Then, I would move on to this task',
                'After that, I would handle this task',
            ]
            : [
                'Сначала я бы занялся этой задачей',
                'Затем я бы перешёл к этой задаче',
                'После этого я бы взялся за эту задачу',
            ];
        foreach (array_slice($plan, 0, 3) as $i => $t) {
            $allowed = Planner::reasons($t, $this->now);
            $reason = $prepared['steps'][$i]['reason'] ?? $allowed[0];
            if (!in_array($reason, $allowed, true)) {
                $reason = $allowed[0];
            }
            $advice = $prepared['steps'][$i]['advice'] ?? null;
            if (!is_string($advice) || trim($advice) === '') {
                $advice = $fallbackLeads[min($i, 2)] .
                    $this->l(', потому что ', ' because ') .
                    $reasonClauses[$reason] .
                    '.';
            }
            $text .=
                "\n\n" .
                ($i + 1) .
                '. <b>' .
                Emojis::escape($t['title']) .
                "</b>\n" .
                Emojis::escape($advice) .
                "\n" . $this->l('Дедлайн: ', 'Deadline: ') .
                Dates::label($t['deadline'], $this->now) .
                '.';
        }
        $text .= "\n\n" .
            $this->l('План целиком: ', 'Full plan: ') .
            $this->durationLabel((int) array_sum(array_column($plan, 'minutes'))) .
            '.';
        $this->say($text, $this->menu());
    }
}
