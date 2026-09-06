import argparse
import asyncio
import importlib
import logging
import pkgutil
import tempfile
from contextlib import suppress
from dataclasses import replace
from datetime import datetime, timedelta
from pathlib import Path

from aiogram import Bot
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.types import BotCommand

import uniflow
from uniflow.application import create_dispatcher
from uniflow.config import Config, ConfigError
from uniflow.context import AppContext
from uniflow.logging_setup import configure_logging
from uniflow.process_lock import polling_lock
from uniflow.services.llm_service import LLMError, LLMService
from uniflow.services.reminder_service import ReminderService

logger = logging.getLogger(__name__)
COMMANDS = [
    ("start", "Главное меню"),
    ("today", "План на сегодня"),
    ("week", "Нагрузка на неделю"),
    ("tasks", "Все задачи"),
    ("add", "Добавить задачу"),
    ("stats", "Статистика"),
    ("demo", "Демонстрационные задачи"),
    ("help", "Помощь"),
    ("cancel", "Отменить ввод"),
    ("settings", "Настройки времени и напоминаний"),
    ("ai", "Разобрать задание"),
    ("explain", "Объяснить план"),
]


async def run(config: Config):
    with polling_lock(config.bot_token):
        await run_polling(config)


async def run_polling(config: Config):
    app = AppContext.create(config)
    await app.database.initialize()
    dispatcher = create_dispatcher(app)
    bot = Bot(config.bot_token, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
    reminder_task = None
    try:
        await bot.delete_webhook(drop_pending_updates=False)
        await bot.set_my_commands(
            [BotCommand(command=cmd, description=text) for cmd, text in COMMANDS]
        )
        reminder_task = asyncio.create_task(
            ReminderService(app.database, bot).run(), name="reminders"
        )
        logger.info(
            "UniFlow запущен, часовой пояс: %s, LLM: %s",
            config.timezone,
            "включён" if config.llm_available else "выключен, используются локальные правила",
        )
        await dispatcher.start_polling(
            bot, allowed_updates=dispatcher.resolve_used_update_types(), close_bot_session=False
        )
    finally:
        if reminder_task:
            reminder_task.cancel()
            with suppress(asyncio.CancelledError):
                await reminder_task
        await app.close_jobs()
        await dispatcher.storage.close()
        await dispatcher.fsm.events_isolation.close()
        await bot.session.close()


async def check_startup(config: Config):
    class OfflineSender:
        async def send_message(self, *args, **kwargs):
            raise RuntimeError("Offline-проверка не должна отправлять сообщения")

    for module in pkgutil.walk_packages(uniflow.__path__, uniflow.__name__ + "."):
        importlib.import_module(module.name)

    
    with tempfile.TemporaryDirectory(prefix="uniflow-check-") as folder:
        app = AppContext.create(
            replace(config, database_path=Path(folder) / "check.db", llm_enabled=False)
        )
        await app.database.initialize()
        dispatcher = create_dispatcher(app)
        assert "message" in dispatcher.resolve_used_update_types()
        assert "callback_query" in dispatcher.resolve_used_update_types()
        await ReminderService(app.database, OfflineSender()).tick()
        await app.close_jobs()
        await dispatcher.storage.close()
        await dispatcher.fsm.events_isolation.close()
    print(
        "OK: конфигурация, SQLite, обработчики, FSM и проверка напоминаний. Сеть не использовалась."
    )


async def check_llm(config: Config):
    if not config.llm_available:
        raise ConfigError(
            "Для --check-llm нужны LLM_ENABLED=true, LLM_API_KEY, LLM_BASE_URL и LLM_MODEL."
        )
    now = datetime.now(config.tz)
    tasks = await LLMService(config).parse("Сдать учебное эссе завтра, 30 минут", now)
    if (
        len(tasks) != 1
        or tasks[0].estimated_minutes != 30
        or tasks[0].deadline is None
        or tasks[0].deadline.astimezone(config.tz).date() != (now.date() + timedelta(days=1))
        or tasks[0].deadline.astimezone(config.tz).strftime("%H:%M") != "23:59"
    ):
        raise LLMError("diagnostic_facts")
    print(
        "OK: получен ответ провайдера, JSON и факты синтетического примера проверены. "
        "Telegram и база не использовались; fallback отсутствует."
    )


def cli() -> int:
    parser = argparse.ArgumentParser(description="UniFlow - Telegram-помощник студента")
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--check", action="store_true", help="Проверка запуска без токена и сети")
    modes.add_argument(
        "--check-llm", action="store_true", help="Один реальный запрос к LLM без Telegram и БД"
    )
    args = parser.parse_args()
    try:
        config = Config.load(require_token=not (args.check or args.check_llm))
        configure_logging(config.bot_token, config.llm_api_key)
        asyncio.run(
            check_startup(config)
            if args.check
            else check_llm(config)
            if args.check_llm
            else run(config)
        )
    except ConfigError as exc:
        print(f"Ошибка настройки: {exc}")
        return 2
    except LLMError as exc:
        print(f"LLM-проверка не пройдена: {exc.category}. См. README.")
        return 1
    except KeyboardInterrupt:
        print("UniFlow остановлен.")
    except Exception as exc:
        
        logger.error(
            "Не удалось запустить UniFlow (%s). Проверь .env, сеть и README.", type(exc).__name__
        )
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(cli())
