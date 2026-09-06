import asyncio
import sqlite3
from datetime import timedelta

import pytest
from aiogram.exceptions import TelegramBadRequest
from aiogram.methods import EditMessageText, SendMessage
from test_handlers import harness
from test_reminders import Sender

from backup_db import backup
from uniflow.config import ConfigError
from uniflow.database import Database
from uniflow.keyboards.main import MENU
from uniflow.process_lock import polling_lock
from uniflow.services.reminder_service import ReminderService


def test_post_commit_send_error_does_not_duplicate_batch(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("Лаба завтра 30 минут")
            save = h.button("✅ Добавить все")
            original = h.session.make_request

            async def failing(bot, method, timeout=None):  
                if isinstance(method, SendMessage) and method.text.startswith("✅ Добавлено задач"):
                    raise TelegramBadRequest(method=method, message="synthetic send failure")
                return await original(bot, method, timeout)

            h.session.make_request = failing
            await h.click(save)
            await h.click(save)
            assert len(await h.app.database.list_tasks(101)) == 1
            assert "Что-то пошло не так" in h.text
            h.session.make_request = original
            await h.send("/today")
            assert "ПЛАН НА СЕГОДНЯ" in h.session.calls[-1].text

    asyncio.run(scenario())


def test_edit_message_failure_falls_back_to_new_message(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/today")
            refresh = h.button("🔄")
            original = h.session.make_request

            async def failing(bot, method, timeout=None):  
                if isinstance(method, EditMessageText):
                    raise TelegramBadRequest(method=method, message="message cannot be edited")
                return await original(bot, method, timeout)

            h.session.make_request = failing
            await h.click(refresh)
            assert isinstance(h.session.calls[-1], SendMessage)
            assert "ПЛАН НА СЕГОДНЯ" in h.session.calls[-1].text

    asyncio.run(scenario())


def test_all_reply_menu_buttons_route(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            for label in sorted(MENU):
                previous = len(h.session.calls)
                await h.send(label)
                assert len(h.session.calls) > previous
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())


def test_polling_lock_rejects_duplicate_and_releases():
    with polling_lock("synthetic-no-api-token"):
        with pytest.raises(ConfigError, match="уже запущен"):
            with polling_lock("synthetic-no-api-token"):
                pytest.fail("Lock must reject duplicate process")
    with polling_lock("synthetic-no-api-token"):
        pass


def test_sqlite_backup_and_refuse_overwrite(tmp_path):
    source = tmp_path / "source.db"
    destination = tmp_path / "backup.db"
    with sqlite3.connect(source) as db:
        db.execute("PRAGMA journal_mode=WAL")
        db.execute("CREATE TABLE sample(id INTEGER)")
        db.execute("INSERT INTO sample VALUES(42)")
        db.commit()
        backup(source, destination)
    with sqlite3.connect(destination) as db:
        assert db.execute("SELECT * FROM sample").fetchall() == [(42,)]
    with pytest.raises(FileExistsError):
        backup(source, destination)
    with pytest.raises(ValueError):
        backup(tmp_path / "absent.db", tmp_path / "unused.db")


def test_complete_local_end_to_end_and_restart(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.click(h.button("Москва"))
            await h.send("/add")
            for value in ("Ручная лаба", "Пропустить", "завтра", "30 минут", "🟡 Важная"):
                await h.send(value)
            await h.send("Сдать эссе завтра; подготовить презентацию")
            await h.click(h.button("✅ Добавить все"))
            for value in ("1 час", "через 3 дня", "90 минут"):
                await h.send(value)
            await h.click(h.button("✅ Добавить все"))
            assert len(await h.app.database.list_tasks(101)) == 3
            await h.send("/today")
            await h.send("/explain")
            await h.click(h.button("Локальный вариант"))
            task = (await h.app.database.list_tasks(101))[0]
            await h.click(f"task:edit:{task.id}")
            await h.click(h.button("Дедлайн"))
            local = await h.app.user_now(101)
            await h.send("сегодня в " + (local + timedelta(hours=2)).strftime("%H:%M"))
            await h.click(h.button("Сохранить"))
            sender = Sender()
            await ReminderService(h.app.database, sender).tick(now)
            assert len(sender.messages) == 1 and sender.messages[0][0] == 101
            await h.click(f"task:done:{task.id}")
            await h.send("/week")
            await h.send("/stats")
            assert "Выполнено: 1" in h.text
            restarted = Database(h.app.database.path)
            await restarted.initialize()
            assert len(await restarted.list_tasks(101, active_only=False)) == 3
            assert (await restarted.get_user(101))["timezone"] == "Europe/Moscow"
            assert (await restarted.get_task(101, task.id)).status == "completed"
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())
