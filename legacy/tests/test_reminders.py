import asyncio
from contextlib import suppress
from datetime import timedelta

from aiogram.exceptions import TelegramForbiddenError
from aiogram.methods import SendMessage

from uniflow.database import Database
from uniflow.models import NewTask
from uniflow.services.reminder_service import ReminderService


class Sender:
    def __init__(self):
        self.messages = []
        self.fail = None

    async def send_message(self, user_id, text, **kwargs):
        if self.fail:
            raise self.fail
        self.messages.append((user_id, text, kwargs))


async def setup_database(tmp_path, now, hours=20):
    db = Database(tmp_path / "reminders.db")
    await db.initialize()
    await db.register_user(1, None, None, "Asia/Yekaterinburg")
    (task,) = await db.create_tasks(
        1, [NewTask(title="Лаба <Java>", deadline=now + timedelta(hours=hours))]
    )
    return db, task


def test_reminders_once_each_even_after_restart(tmp_path, now):
    async def scenario():
        db, task = await setup_database(tmp_path, now)
        bot = Sender()
        service = ReminderService(db, bot)
        await asyncio.gather(service.tick(now), service.tick(now))
        assert len(bot.messages) == 1
        assert "24 ч" in bot.messages[0][1] and "&lt;Java&gt;" in bot.messages[0][1]
        restarted = ReminderService(Database(db.path), bot)
        await restarted.tick(now)
        await restarted.tick(now + timedelta(hours=18))
        await restarted.tick(now + timedelta(hours=18))
        assert len(bot.messages) == 2
        assert "3 ч" in bot.messages[1][1]
        updated = await db.get_task(1, task.id)
        assert updated.reminder_24h_sent and updated.reminder_3h_sent

    asyncio.run(scenario())


def test_start_near_deadline_sends_only_3h(tmp_path, now):
    async def scenario():
        db, _ = await setup_database(tmp_path, now, 2)
        bot = Sender()
        service = ReminderService(db, bot)
        await service.tick(now)
        await service.tick(now)
        assert len(bot.messages) == 1 and "3 ч" in bot.messages[0][1]

    asyncio.run(scenario())


def test_failure_retries_then_stops_after_success(tmp_path, now):
    async def scenario():
        db, task = await setup_database(tmp_path, now)
        bot = Sender()
        bot.fail = OSError("offline")
        service = ReminderService(db, bot)
        await service.tick(now)
        assert not (await db.get_task(1, task.id)).reminder_24h_sent
        bot.fail = None
        await service.tick(now)
        await service.tick(now)
        assert len(bot.messages) == 1

    asyncio.run(scenario())


def test_completed_overdue_and_deleted_are_not_notified(tmp_path, now):
    async def scenario():
        db, task = await setup_database(tmp_path, now)
        await db.complete_task(1, task.id, now)
        second, third = await db.create_tasks(
            1,
            [
                NewTask(title="Удалена", deadline=now + timedelta(hours=1)),
                NewTask(title="Просрочена", deadline=now - timedelta(seconds=1)),
            ],
        )
        await db.delete_task(1, second.id)
        bot = Sender()
        await ReminderService(db, bot).tick(now)
        assert not bot.messages
        assert (await db.get_task(1, third.id)).status == "active"

    asyncio.run(scenario())


def test_blocked_user_does_not_break_other_users(tmp_path, now):
    async def scenario():
        db, _ = await setup_database(tmp_path, now)
        await db.register_user(2, None, None, "Asia/Yekaterinburg")
        await db.create_tasks(2, [NewTask(title="Второй", deadline=now + timedelta(hours=21))])

        class SelectiveSender(Sender):
            async def send_message(self, user_id, text, **kwargs):
                if user_id == 1:
                    raise TelegramForbiddenError(
                        method=SendMessage(chat_id=1, text="test"), message="blocked"
                    )
                return await super().send_message(user_id, text, **kwargs)

        bot = SelectiveSender()
        service = ReminderService(db, bot)
        await service.tick(now)
        await service.tick(now + timedelta(hours=19))
        assert [m[0] for m in bot.messages] == [2, 2]

    asyncio.run(scenario())


def test_background_loop_survives_error_and_cancels(tmp_path, now):
    async def scenario():
        db, _ = await setup_database(tmp_path, now)
        service = ReminderService(db, Sender(), interval=0.001)
        second_tick = asyncio.Event()
        calls = 0

        async def tick():
            nonlocal calls
            calls += 1
            if calls == 1:
                raise OSError("temporary database problem")
            second_tick.set()

        service.tick = tick
        task = asyncio.create_task(service.run())
        await asyncio.wait_for(second_tick.wait(), timeout=1)
        task.cancel()
        with suppress(asyncio.CancelledError):
            await task
        assert task.cancelled() and calls >= 2

    asyncio.run(scenario())
