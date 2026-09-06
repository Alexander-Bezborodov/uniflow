import asyncio
import sqlite3
from datetime import timedelta

import pytest
from test_handlers import harness

from uniflow.database import SCHEMA, Database
from uniflow.models import NewTask
from uniflow.utils.dates import utc_string


def test_legacy_migration_preserves_every_existing_field(tmp_path, now):
    path = tmp_path / "legacy.db"
    with sqlite3.connect(path) as db:
        db.row_factory = sqlite3.Row
        db.executescript(SCHEMA)
        db.execute(
            "INSERT INTO users VALUES(1, NULL, NULL, ?, ?, 1)", ("Europe/Moscow", utc_string(now))
        )
        db.execute(
            "INSERT INTO users VALUES(2, NULL, NULL, ?, ?, 0)",
            ("Asia/Yekaterinburg", utc_string(now)),
        )
        for owner, status, demo in ((1, "active", 0), (1, "completed", 1), (2, "active", 0)):
            db.execute(
                """INSERT INTO tasks(user_id,title,deadline,created_at,status,completed_at,is_demo,
                       reminder_24h_sent) VALUES(?,?,?,?,?,?,?,1)""",
                (
                    owner,
                    "Лаба",
                    utc_string(now),
                    utc_string(now - timedelta(days=1)),
                    status,
                    utc_string(now) if status == "completed" else None,
                    demo,
                ),
            )
        before_tasks = [dict(r) for r in db.execute("SELECT * FROM tasks")]
        before_users = [dict(r) for r in db.execute("SELECT * FROM users")]

    async def scenario():
        db = Database(path)
        await db.initialize()
        await db.initialize()
        async with db.connect() as con:
            assert (await (await con.execute("PRAGMA user_version")).fetchone())[0] == 1
            after_tasks = await (await con.execute("SELECT * FROM tasks")).fetchall()
            after_users = await (await con.execute("SELECT * FROM users")).fetchall()
        for before, after in zip(
            before_tasks + before_users, after_tasks + after_users, strict=True
        ):
            assert before == {key: after[key] for key in before}

    asyncio.run(scenario())


def test_edit_concurrency_and_owner_scope(tmp_path, now):
    async def scenario():
        db = Database(tmp_path / "edit.db")
        await db.initialize()
        await db.register_user(1, None, None, "UTC")
        task = (
            await db.create_tasks(1, [NewTask(title="Лаба", deadline=now - timedelta(days=1))])
        )[0]
        assert not await db.update_task(2, task.id, 0, "title", "Чужая", now)
        results = await asyncio.gather(
            db.update_task(1, task.id, 0, "title", "А", now),
            db.update_task(1, task.id, 0, "title", "Б", now),
        )
        assert sorted(results) == [False, True]
        updated = await db.get_task(1, task.id)
        assert updated.revision == 1 and updated.deadline == task.deadline
        with pytest.raises(ValueError):
            await db.update_task(1, task.id, 1, "deadline", now - timedelta(hours=1), now)

    asyncio.run(scenario())


@pytest.mark.parametrize(
    ("button", "value", "field", "expected"),
    [
        ("Название", "Лаба <новая>", "title", "Лаба <новая>"),
        ("Предмет", "Пропустить", "subject", None),
        ("Дедлайн", "через 3 дня в 18:17", "deadline", None),
        ("Длительность", "1 час 30 минут", "estimated_minutes", 90),
        ("Важность", "🔴 Очень важная", "importance", 3),
    ],
)
def test_saved_edit_each_field_confirm_and_cancel(tmp_path, now, button, value, field, expected):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            task = (
                await h.app.database.create_tasks(
                    101,
                    [
                        NewTask(
                            title="Лаба", subject="Математика", deadline=now - timedelta(hours=1)
                        )
                    ],
                )
            )[0]
            await h.click(f"task:detail:{task.id}")
            await h.click(h.button("✏️ Редактировать"))
            await h.click(h.button(button))
            await h.send(value)
            assert (await h.app.database.get_task(101, task.id)).revision == 0
            await h.click(h.button("Отмена"))
            assert (await h.app.database.get_task(101, task.id)).revision == 0
            await h.click(f"task:edit:{task.id}")
            await h.click(h.button(button))
            await h.send(value)
            save = h.button("Сохранить")
            await asyncio.gather(h.click(save), h.click(save))
            updated = await h.app.database.get_task(101, task.id)
            assert updated.revision == 1
            if field == "deadline":
                assert updated.deadline.astimezone(now.tzinfo).day == 8
                assert updated.deadline.minute == 17
            else:
                assert getattr(updated, field) == expected
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())
