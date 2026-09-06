import asyncio
from datetime import timedelta

from aiogram.exceptions import TelegramRetryAfter
from aiogram.methods import SendMessage
from test_reminders import Sender, setup_database

from uniflow.database import Database
from uniflow.services.reminder_service import ReminderService


def test_deadline_edit_resets_flags_and_stale_snapshot_is_skipped(tmp_path, now):
    async def scenario():
        db, task = await setup_database(tmp_path, now)
        sender = Sender()
        service = ReminderService(db, sender)
        await service.tick(now)
        assert len(sender.messages) == 1
        await db.update_task(1, task.id, 0, "deadline", now + timedelta(hours=2), now)
        updated = await db.get_task(1, task.id)
        assert not updated.reminder_24h_sent and not updated.reminder_3h_sent
        await service.tick(now)
        assert len(sender.messages) == 2 and "3 ч" in sender.messages[-1][1]
        await db.update_task(1, task.id, 1, "deadline", now + timedelta(hours=20), now)
        original = db.reminder_candidates

        async def candidates(current):
            snapshot = await original(current)
            await db.update_task(1, task.id, 2, "deadline", now + timedelta(days=4), now)
            return snapshot

        db.reminder_candidates = candidates
        await service.tick(now)
        assert len(sender.messages) == 2

    asyncio.run(scenario())


def test_retry_after_does_not_sleep_or_resend_early(tmp_path, now, monkeypatch):
    async def scenario():
        db, _ = await setup_database(tmp_path, now)
        sender = Sender()
        sender.fail = TelegramRetryAfter(
            method=SendMessage(chat_id=1, text="x"), message="retry", retry_after=120
        )
        service = ReminderService(db, sender)
        clock = [100.0]
        monkeypatch.setattr("uniflow.services.reminder_service.time.monotonic", lambda: clock[0])
        await service.tick(now)
        sender.fail = None
        clock[0] += 119
        await service.tick(now)
        assert not sender.messages
        clock[0] += 1
        await service.tick(now)
        assert len(sender.messages) == 1

    asyncio.run(scenario())


def test_manual_disable_survives_contact_and_block_is_separate(tmp_path, now):
    async def scenario():
        db, _ = await setup_database(tmp_path, now)
        await db.set_preferences(1, reminders_disabled=1)
        await db.mark_blocked(1)
        await db.register_user(1, None, None, "Europe/Moscow")
        user = await db.get_user(1)
        assert user["reminders_disabled"] and not user["telegram_blocked"]
        sender = Sender()
        await ReminderService(db, sender).tick(now)
        assert not sender.messages
        await db.set_preferences(1, reminders_disabled=0, timezone="Europe/Moscow")
        await ReminderService(db, sender).tick(now)
        assert "06 сентября" not in sender.messages[0][1]  
        assert "Дедлайн:" in sender.messages[0][1]

    asyncio.run(scenario())


def test_completion_waits_for_inflight_send_then_prevents_more(tmp_path, now):
    async def scenario():
        db, task = await setup_database(tmp_path, now)
        started, release = asyncio.Event(), asyncio.Event()

        class SlowSender(Sender):
            async def send_message(self, *args, **kwargs):
                started.set()
                await release.wait()
                await super().send_message(*args, **kwargs)

        sender = SlowSender()
        service = ReminderService(db, sender)
        tick = asyncio.create_task(service.tick(now))
        await started.wait()
        complete = asyncio.create_task(db.complete_task(1, task.id, now))
        release.set()
        await asyncio.gather(tick, complete)
        await service.tick(now + timedelta(hours=18))
        assert len(sender.messages) == 1
        assert (await db.get_task(1, task.id)).status == "completed"

    asyncio.run(scenario())


def test_delivery_flag_failure_can_duplicate_but_does_not_lose_notice(tmp_path, now):
    async def scenario():
        db, _ = await setup_database(tmp_path, now)
        sender = Sender()

        async def cannot_commit(*args):
            raise OSError("synthetic disk fault")

        db.mark_reminder = cannot_commit
        await ReminderService(db, sender).tick(now)
        await ReminderService(Database(db.path), sender).tick(now)
        assert len(sender.messages) == 2  

    asyncio.run(scenario())
