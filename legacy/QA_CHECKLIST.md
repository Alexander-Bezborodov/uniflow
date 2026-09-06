# QA: сценарии и фактические результаты

Дата проверки: 05.09.2026. Число тестов и результаты обязательных команд - [VERIFICATION.md](VERIFICATION.md).
Все перечисленные автоматические тесты фактически запущены. Статус **Авто: PASS** не означает
проверку настоящего Telegram/Groq. Базы временные; сообщения синтетические.

Для компактности имена ниже соответствуют тестам в папке `tests`.

## Время

| Сценарий | Метод / свидетельство | Результат |
| --- | --- | --- |
| Один 19:30Z, Москва 5 сентября 22:30 и Екатеринбург 6 сентября 00:30 | test_headers_follow_user_time, test_settings_two_users_restart_and_absolute_deadlines, через Dispatcher | Авто: PASS |
| 23:59 → 00:00, полночь UTC без смены локального дня | test_headers_follow_user_time, test_refresh_rollover_and_due_filter | Авто: PASS |
| Конец месяца, конец года | test_headers_follow_user_time, test_explicit_time_and_new_year | Авто: PASS |
| Високосная дата, неверный год/месяц/день | test_strict_calendar_and_leap_dates, test_invalid_date | Авто: PASS |
| DST: gap и первое вхождение fold | test_dst_gap_fold_and_weekday_rules, Europe/Berlin | Авто: PASS |
| Завтра, послезавтра, через 3 дня, 10.09, полный год, месяц словом, пятница | test_russian_deadlines | Авто: PASS |
| Явные час и минуты; дата без времени 23:59 | test_explicit_time_and_new_year, test_russian_deadlines, сохранение через Dispatcher | Авто: PASS |
| Дата без года, ближайший/следующий день недели | test_strict_calendar_and_leap_dates, test_dst_gap_fold_and_weekday_rules | Авто: PASS |
| Истечение во время ручного FSM | test_manual_deadline_expires_before_save - исходно падал | Авто: PASS |
| Истечение предпросмотра и редактируемого срока перед подтверждением | test_preview_expires_and_rechecks_missing_fields, test_saved_deadline_expires_at_confirmation | Авто: PASS |
| Смена пояса не меняет UTC-дедлайн; повторный /start и перезапуск | test_settings_two_users_restart_and_absolute_deadlines | Авто: PASS |
| Заголовок /today, /week и дата фильтра - пояс владельца | test_refresh_rollover_and_due_filter | Авто: PASS |
| LLM-контекст - пояс владельца | test_explanation_owner_facts_and_changes_during_request, test_ai_preview_unknown_minutes_confirm_is_atomic_and_no_extra_calls | Авто: PASS |
| Скриншот исходной проблемы | Просмотр предоставленного PNG: неподписанные строки дат; времени съёмки нет | Ручной просмотр исходника; причина разницы даты не доказана |

## Бизнес-логика, данные и напоминания

| Сценарий | Метод / свидетельство | Результат |
| --- | --- | --- |
| Все временные границы приоритетов, важность/длительность, стабильная сортировка | test_priority.py | Авто: PASS |
| План на сегодня, завтра, будущие сроки, просрочка, пустой список | test_planning.py, test_time_settings.py | Авто: PASS |
| Нулевые/граничные оценки, точная сумма, первый день недели, работа не остаётся после будущего срока | test_week_conserves_work_before_deadline; исходные planning tests | Авто: PASS |
| 30 минут, 1 час, 1.5/1,5 часа, 1 час 30 минут | test_duration, test_saved_edit_each_field_confirm_and_cancel | Авто: PASS |
| Отрицательная/нулевая/слишком большая длительность, важность вне диапазона | test_invalid_duration, test_new_task_requires_valid_deadline_and_minutes, test_server_schema_rejects_types_and_dates | Авто: PASS |
| Сохранение после перезапуска, атомарность пачки, foreign keys | test_database.py | Авто: PASS |
| Конкурентное редактирование, версия задачи, чужой владелец | test_edit_concurrency_and_owner_scope | Авто: PASS |
| Синтетическая старая БД: ID/владельцы/сроки/статусы/завершения/пояса; повторная миграция | test_legacy_migration_preserves_every_existing_field | Авто: PASS |
| Backup SQLite с WAL и запрет перезаписи | test_sqlite_backup_and_refuse_overwrite | Авто: PASS |
| Статистика: пусто, вовремя, поздно, активно/просрочено | test_statistics_empty_and_mixed; end-to-end | Авто: PASS |
| Demo заменяет только свои demo, реальные задачи и чужие данные сохраняются | test_demo_only_replaces_demo_for_current_user, test_demo_confirmation_and_stale_callbacks | Авто: PASS |
| Напоминания 24/3 ч, старт сразу внутри 3 ч, успешная отправка один раз и перезапуск | test_reminders_once_each_even_after_restart, test_start_near_deadline_sends_only_3h | Авто: PASS, mock Telegram |
| Сетевая ошибка, повтор после восстановления; ошибка фонового цикла, отмена | test_failure_retries_then_stops_after_success, test_background_loop_survives_error_and_cancels | Авто: PASS, mock |
| Блокировка бота одним пользователем не мешает другому | test_blocked_user_does_not_break_other_users | Авто: PASS, mock |
| Ручное выключение сохраняется; техническая блокировка отдельно | test_manual_disable_survives_contact_and_block_is_separate | Авто: PASS |
| TelegramRetryAfter: нет ранней отправки и блокирующего sleep | test_retry_after_does_not_sleep_or_resend_early | Авто: PASS, mock времени/API |
| Редактирование срока сбрасывает флаги, устаревший снимок пропускается | test_deadline_edit_resets_flags_and_stale_snapshot_is_skipped | Авто: PASS |
| Завершение/удаление исключают следующие уведомления; отправка в полёте упорядочена | test_completed_overdue_and_deleted_are_not_notified, test_completion_waits_for_inflight_send_then_prevents_more | Авто: PASS |
| Аварийное окно доставка → запись: возможный повтор | test_delivery_flag_failure_can_duplicate_but_does_not_lose_notice | Авто: PASS, синтетическая ошибка записи |
| Реальная миграция/восстановление личной базы пользователя | Личная БД не предоставлялась; инструкция и backup-скрипт готовы | Не проверено на личных данных |

## Telegram и UX

| Сценарий | Метод / свидетельство | Результат |
| --- | --- | --- |
| /start, /today, /week, /tasks, /add, /stats, /demo, /help, /cancel | test_handlers.py через настоящий Dispatcher + FakeSession | Авто: PASS |
| /settings, /ai, /explain, все reply-кнопки | test_time_settings.py, test_ai_workflows.py, test_all_reply_menu_buttons_route | Авто: PASS, Dispatcher |
| Ручной FSM: пропуск предмета, неверный повторный ввод, отмена, меню | test_start_manual_add_menu_complete_delete, test_cancel_navigation_and_partial_quick_input | Авто: PASS, Dispatcher |
| Редактирование каждого из пяти сохранённых полей, видимые старые данные, отмена до записи | test_saved_edit_each_field_confirm_and_cancel (5 случаев) | Авто: PASS, Dispatcher |
| Название просроченной задачи меняется без принуждения к новому сроку | test_saved_edit_each_field_confirm_and_cancel, test_edit_concurrency_and_owner_scope | Авто: PASS |
| Повторное завершение, подтверждение/отмена удаления, чужие/удалённые/завершённые кнопки | test_handlers.py; test_migration_edit.py | Авто: PASS |
| Двойные callbacks «Добавить все» и «Демо», старый предпросмотр | test_teacher_preview_fill_missing_edit_and_double_click, test_demo_confirmation_and_stale_callbacks | Авто: PASS |
| Пагинация, обновление текущей страницы, fallback на новое сообщение | test_pagination_and_global_error_recovery, test_edit_message_failure_falls_back_to_new_message | Авто: PASS |
| Unicode, emoji, HTML, длинные заголовки/предметы, предел 4096 | test_long_unicode_html_cards_pagination_and_unsupported | Авто: PASS, расчёт длины; сервер Telegram не участвовал |
| Пустой/нетекстовый ввод в форме и вне формы | test_long_unicode_html_cards_pagination_and_unsupported | Авто: PASS, синтетические updates |
| Отмена медленного LLM и поздний ответ; смена сценария | test_cancel_late_response_does_not_reopen_preview, test_navigation_late_answer_and_independent_user | Авто: PASS |
| Другой пользователь во время медленного AI-запроса | test_navigation_late_answer_and_independent_user | Авто: PASS |
| Сбой отправки подтверждения после успешного INSERT, затем старое подтверждение | test_post_commit_send_error_does_not_duplicate_batch | Авто: PASS |
| Глобальный error handler, затем успешная команда | test_pagination_and_global_error_recovery | Авто: PASS |
| Единственная reminder-задача и закрытие ресурсов при остановке | test_run_starts_reminders_and_closes_everything | Авто: PASS, polling подменён |
| Дублирующий polling-процесс на той же машине | test_polling_lock_rejects_duplicate_and_releases | Авто: PASS, OS-lock на macOS |
| Визуальный просмотр нового UI на мобильном/desktop Telegram, живое polling | Нужен собственный тестовый бот, токен не предоставлен | Не проверено |

## ИИ

| Сценарий | Метод / свидетельство | Результат |
| --- | --- | --- |
| Реальный aiohttp ClientSession по TCP к поддельному серверу; URL, Bearer, payload, JSON | test_real_aiohttp_transport_to_local_fake_provider | Авто: PASS, только 127.0.0.1, не Groq |
| Strict json_schema для Groq, все обязательные nullable-поля, запрет extras | test_local_global_limits_and_strict_schema, test_server_schema_rejects_types_and_dates, test_strict_llm_json_validation | Авто: PASS |
| HTTP 400/401/403/404/429/500/502/503 | test_http_error_categories_and_retry_after | Авто: PASS, локальный HTTP-сервер |
| Пустой/оборванный/невалидный/слишком большой JSON, refusal, finish_reason=length | test_malformed_http_responses | Авто: PASS, локальный HTTP-сервер |
| Таймаут, обрыв соединения | test_every_provider_failure_falls_back; test_connection_failure_fallback | Авто: PASS, подменённые исключения транспорта |
| User/process quota; Retry-After; повторный callback не расходует API | test_local_global_limits_and_strict_schema, test_http_error_categories_and_retry_after, test_ai_workflows.py | Авто: PASS |
| Выключен LLM / нет ключа / неполные настройки | test_optional_llm_missing_key_never_prevents_start, test_startup_check_does_not_touch_real_database | Авто: PASS |
| Явный локальный выбор и честная подпись fallback | test_ai_error_source_and_local_option_do_not_call_provider | Авто: PASS, Dispatcher |
| Нет выдуманной оценки, неверный/отдельный/общий срок, разные даты при перестановке задач моделью | test_openai_compatible_request_and_strict_response; test_regressions.py; test_reordered_provider_tasks_keep_their_own_deadlines | Авто: PASS |
| Prompt injection: недоверенный текст, нет инструментов, запись только после подтверждения | test_openai_compatible_request_and_strict_response; test_ai_preview_unknown_minutes_confirm_is_atomic_and_no_extra_calls | Авто: PASS для проверенных инвариантов; поведение настоящей модели не проверено |
| Объяснение: только свой план, правильные ссылки/минуты/даты/причины, без изменения БД | test_explanation_rejects_invented_facts, test_explanation_owner_facts_and_changes_during_request | Авто: PASS |
| Изменение данных во время объяснения → актуальный локальный результат | test_explanation_owner_facts_and_changes_during_request | Авто: PASS |
| Пустой план не обращается к API | test_valid_and_failed_explanations_and_empty_plan | Авто: PASS |
| /cancel не создаёт задачи и не открывает предпросмотр поздно | test_cancel_late_response_does_not_reopen_preview | Авто: PASS |
| --check-llm не принимает fallback за успех; нет ключа → ненулевой код | test_check_llm_never_accepts_fallback и фактический CLI без ключа | Авто/CLI: PASS отрицательной ветки, не успешный API-вызов |
| Реальный Groq, аккаунт/регион/квоты, обе функции с настоящими ответами | API-ключ отсутствует; инструкции в README | Не проверено |

## Сквозной сценарий

`test_complete_local_end_to_end_and_restart` фактически выполняет через Dispatcher:
/start → Москва → ручная задача → быстрый текст с двумя задачами → уточнение полей →
сохранение → /today → локальное объяснение → редактирование срока → тестовое напоминание →
завершение → /week → /stats → повторное открытие SQLite с сохранёнными задачами и настройками.
Результат: **Авто: PASS**, Telegram подменён.

AI-ветка разбора/предпросмотра/подтверждения дополнительно проходит через Dispatcher в
`test_ai_preview_unknown_minutes_confirm_is_atomic_and_no_extra_calls`, а редактирование
предпросмотра - в `test_teacher_preview_fill_missing_edit_and_double_click`.

Полный живой сценарий с настоящим Groq, Telegram, уведомлением и перезапуском процесса:
**не проведён**. Владелец запускает его на собственном тестовом боте по README.
Проверок с рассылкой реальным пользователям не было.

## Среда и блокеры

Изначально среда запрещала локальные сокеты: HTTP-тесты упали с PermissionError до запроса.
После явно выданного сетевого разрешения они повторно запущены и прошли.
Пропусками эти первоначальные ошибки не скрывались. Итоговые прогоны - без skip/xfail.
Секреты и личная БД для автономных тестов не понадобились.
