# Фактически выполненные проверки UniFlow 0.2

Дата: 05.09.2026. macOS ARM64.
Основной прогон: отдельное виртуальное окружение Python 3.12.14, установленное из requirements.txt.
Дополнительный прогон: Python 3.14.0 с теми же закреплёнными прямыми зависимостями.

| Команда / среда | Код | Результат |
| --- | --- | --- |
| `python -m pytest` · Python 3.12.14 | 0 | **192 passed**, без skip/xfail |
| `ruff check .` | 0 | All checks passed! |
| `ruff format --check .` | 0 | 52 files already formatted |
| `python -m pip check` | 0 | No broken requirements found |
| `python -c "import main"` | 0 | Импорт успешен, сетевых/БД-действий нет |
| `python main.py --check` | 0 | Конфигурация, временная SQLite, handlers/FSM и пустой проход напоминаний |
| Импорт через pkgutil/importlib, main и backup_db | 0 | Imported 31 modules |
| `python main.py --check-llm` без настроенного ключа | **2** | Ожидаемая ошибка конфигурации; **не успешный вызов Groq** |
| `python -m pytest -q` · Python 3.14.0 | 0 | **192 passed**, без skip/xfail |

`pip check` дополнительно сообщил о недоступном каталоге кэша pip и отключил кэш;
зависимости не повреждены, код завершения 0. Никакие предупреждения не скрывались тестовыми skip.

До исправлений исходные 109 тестов действительно проходили на Python 3.14.0.
Три добавленных регрессии сначала упали: сброс пользовательских настроек, перенос срока
первой задачи на вторую и истечение дедлайна в ручном FSM. Позже отдельно воспроизведён
крайний случай с ошибочным коротким годом. После исправлений они проходят.
В заключительном прогоне найден и исправлен E501 в system prompt; ruff повторно прошёл.

## Что именно проверено

Telegram-тесты подают Update в настоящий aiogram Dispatcher, но используют FakeSession:
сообщения в Telegram не отправляются. LLM-транспорт проверен настоящим aiohttp ClientSession
с TCP-сервером на 127.0.0.1 и синтетическими ответами. Обработка таймаута и обрыва дополнительно
проверена подменой исключений. Это **не** тест реального провайдера.

Миграции, резервные копии, владельцы, конкурентные изменения и перезапуски проверены
на временных синтетических SQLite. Ручного изменения личной базы не было.
Ни BOT_TOKEN, ни LLM_API_KEY, ни рабочий .env для разработки не использовались.

Полная матрица: [QA_CHECKLIST.md](QA_CHECKLIST.md).
Причины и изменения: [BUGFIX_REPORT.md](BUGFIX_REPORT.md).
Запуск и самостоятельный сетевой тест: [README.md](README.md).

## Что не проверено

- Настоящий Telegram polling, визуальное отображение нового UI и реальные напоминания.
- Настоящий Groq: действующий ключ, регион/аккаунт, квоты и качество двух AI-функций.
- Windows/Linux, реальные данные и восстановление личной базы пользователя.

Для Groq владелец заполняет локальный .env и явно запускает `python main.py --check-llm`.
Эта команда не принимает fallback за успех. Затем нужно проверить обе функции в собственном
тестовом боте. Не следует считать существующий LLM-класс или успешный fallback доказательством
настоящей API-интеграции.

## Вывод фактического pytest на Python 3.12.14

```text
============================= test session starts ==============================
platform darwin -- Python 3.12.14, pytest-9.1.1, pluggy-1.6.0
rootdir: <распакованный проект uniflow>
configfile: pyproject.toml
testpaths: tests
collected 192 items

tests/test_ai_workflows.py .......                                       [  3%]
tests/test_config_startup.py .....                                       [  6%]
tests/test_database.py .....                                             [  8%]
tests/test_delivery_startup.py ......                                    [ 11%]
tests/test_handlers.py ......                                            [ 15%]
tests/test_llm.py ..........                                             [ 20%]
tests/test_llm_transport.py ..............................               [ 35%]
tests/test_migration_edit.py .......                                     [ 39%]
tests/test_parser.py ................................................... [ 66%]
..                                                                       [ 67%]
tests/test_planning.py ............                                      [ 73%]
tests/test_priority.py ............                                      [ 79%]
tests/test_regressions.py ........                                       [ 83%]
tests/test_reminder_edits.py .....                                       [ 86%]
tests/test_reminders.py ......                                           [ 89%]
tests/test_time_settings.py ....................                         [100%]

============================= 192 passed in 2.66s ==============================
```
