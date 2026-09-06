import asyncio
import logging
import time
from datetime import UTC, datetime
from html import escape
from zoneinfo import ZoneInfo

from aiogram import Bot
from aiogram.exceptions import TelegramForbiddenError, TelegramRetryAfter

from uniflow.database import Database
from uniflow.keyboards.main import inline
from uniflow.utils.dates import date_label
from uniflow.utils.formatting import duration

logger = logging.getLogger(__name__)


class ReminderService:
    def __init__(self, database: Database, bot: Bot, interval: float = 60):
        self.database = database
        self.bot = bot
        self.interval = interval
        self._tick_lock = asyncio.Lock()
        self.retry_at = 0.0

    async def tick(self, now: datetime | None = None):
        async with self._tick_lock:
            if time.monotonic() < self.retry_at:
                return
            current = now or datetime.now(UTC)
            for candidate, _ in await self.database.reminder_candidates(current):
                
                
                async with self.database.user_lock(candidate.user_id):
                    task = await self.database.get_task(candidate.user_id, candidate.id)
                    user = await self.database.get_user(candidate.user_id)
                    if (
                        task is None
                        or task.status != "active"
                        or task.revision != candidate.revision
                        or user["reminders_disabled"]
                        or user["telegram_blocked"]
                    ):
                        continue
                    seconds = task.deadline.timestamp() - current.timestamp()
                    hours = 3 if seconds <= 10800 else 24
                    if not 0 < seconds <= 86400 or task.reminder_3h_sent:
                        continue
                    if hours == 24 and task.reminder_24h_sent:
                        continue
                    try:
                        local = current.astimezone(ZoneInfo(user["timezone"]))
                        text = (
                            "⚠️ <b>ДЕДЛАЙН ПРИБЛИЖАЕТСЯ</b>\n\n"
                            f"<b>{escape(task.title)}</b>\n📅 Осталось не более {hours} ч\n"
                            f"Дедлайн: {date_label(task.deadline, local)}\n\n"
                            f"⏱ Оценка всего: {duration(task.estimated_minutes)}."
                        )
                        await self.bot.send_message(
                            task.user_id,
                            text,
                            parse_mode="HTML",
                            reply_markup=inline(
                                [
                                    [("📚 Открыть план", "nav:today")],
                                    [("✅ Уже сделал", f"task:done:{task.id}")],
                                ]
                            ),
                        )
                        
                        
                        await self.database.mark_reminder(
                            task.user_id, task.id, hours, task.revision
                        )
                    except TelegramForbiddenError:
                        await self.database.mark_blocked(task.user_id)
                        logger.info("Напоминание: telegram_blocked")
                    except TelegramRetryAfter as exc:
                        self.retry_at = time.monotonic() + max(0, exc.retry_after)
                        logger.warning("Напоминание: telegram_rate_limit")
                        return
                    except Exception as exc:
                        logger.warning("Напоминание: %s", type(exc).__name__)

    async def run(self):
        logger.info("Фоновая проверка напоминаний запущена")
        while True:
            try:
                await self.tick()
            except Exception as exc:
                logger.error("Ошибка проверки напоминаний (%s)", type(exc).__name__)
            await asyncio.sleep(self.interval)
