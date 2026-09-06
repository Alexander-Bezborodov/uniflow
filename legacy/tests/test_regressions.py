import asyncio
from datetime import UTC, datetime, timedelta
from zoneinfo import ZoneInfo

from test_handlers import harness

from uniflow.database import Database
from uniflow.services.parser_service import heuristic_parse


def test_registration_preserves_preferences(tmp_path):
    async def scenario():
        db = Database(tmp_path / "prefs.db")
        await db.initialize()
        await db.register_user(1, None, None, "Europe/Moscow")
        await db.disable_reminders(1)
        await db.register_user(1, "updated", None, "Asia/Yekaterinburg")
        async with db.connect() as con:
            row = await (await con.execute("SELECT * FROM users")).fetchone()
        assert row["timezone"] == "Europe/Moscow"
        assert row["reminders_disabled"] == 1

    asyncio.run(scenario())


def test_separate_unknown_and_invalid_deadlines(now):
    for suffix in ("подготовить презентацию", "подготовить презентацию 31.02"):
        tasks = heuristic_parse("Сдать эссе завтра; " + suffix, now)
        assert tasks[0].deadline is not None
        assert tasks[1].deadline is None


def test_manual_deadline_expires_before_save(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/add")
            for text in ("Лаба", "Пропустить", "сегодня в 12:01", "30 минут"):
                await h.send(text)
            h.app.now = lambda: now + timedelta(minutes=2)
            await h.send("🟡 Важная")
            assert not await h.app.database.list_tasks(101)

    asyncio.run(scenario())


def test_same_instant_has_two_local_dates():
    now = datetime(2026, 9, 5, 19, 30, tzinfo=UTC)
    assert now.astimezone(ZoneInfo("Europe/Moscow")).day == 5
    assert now.astimezone(ZoneInfo("Asia/Yekaterinburg")).day == 6


def test_malformed_separate_date_is_not_common_deadline(now):
    tasks = heuristic_parse("Коллеги, завтра нужно сделать дз; сдать эссе 10.09.20", now)
    assert tasks[0].deadline.day == 6
    assert tasks[1].deadline is None


def test_negative_quick_duration_and_terminal_punctuation(now):
    task = heuristic_parse("Сдать эссе завтра.", now)[0]
    assert task.deadline.day == 6
    assert heuristic_parse("Лаба завтра -1 час", now)[0].estimated_minutes is None


def test_preview_expires_and_rechecks_missing_fields(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("Лаба сегодня в 12:01 30 минут")
            confirm = h.button("✅ Добавить все")
            h.app.clock = lambda: now + timedelta(minutes=2)
            await h.click(confirm)
            assert not await h.app.database.list_tasks(101)
            assert await h.state().get_state() == "Form:deadline"
            await h.send("завтра")
            await h.click(h.button("✅ Добавить все"))
            assert len(await h.app.database.list_tasks(101)) == 1

    asyncio.run(scenario())


def test_saved_deadline_expires_at_confirmation(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("Лаба завтра 30 минут")
            await h.click(h.button("✅ Добавить все"))
            original = (await h.app.database.list_tasks(101))[0]
            await h.click(f"task:edit:{original.id}")
            await h.click(h.button("Дедлайн"))
            await h.send("сегодня в 12:01")
            h.app.clock = lambda: now + timedelta(minutes=2)
            await h.click(h.button("Сохранить"))
            assert (await h.app.database.get_task(101, original.id)).deadline == original.deadline
            assert await h.state().get_state() == "Form:deadline"

    asyncio.run(scenario())
