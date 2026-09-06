import asyncio
from datetime import timedelta

import pytest

from uniflow.database import Database
from uniflow.models import NewTask
from uniflow.services.task_service import TaskService


def test_persistence_user_isolation_and_idempotent_complete(tmp_path, now):
    async def scenario():
        path = tmp_path / "tasks.db"
        db = Database(path)
        await db.initialize()
        await db.register_user(1, None, "Первый", "Asia/Yekaterinburg")
        await db.register_user(2, None, "Второй", "Asia/Yekaterinburg")
        (task,) = await db.create_tasks(
            1, [NewTask(title="Лаба", deadline=now + timedelta(days=1))]
        )
        restarted = Database(path)
        await restarted.initialize()
        assert (await restarted.get_task(1, task.id)).title == "Лаба"
        assert await restarted.get_task(2, task.id) is None
        assert await restarted.list_tasks(2) == []
        assert not await restarted.delete_task(2, task.id)
        assert not await restarted.complete_task(2, task.id, now)
        results = await asyncio.gather(
            *(restarted.complete_task(1, task.id, now) for _ in range(3))
        )
        assert results.count(True) == 1
        assert await restarted.list_tasks(1) == []
        assert (await restarted.get_task(1, task.id)).completed_at == now
        assert not await restarted.delete_task(1, task.id)

    asyncio.run(scenario())


def test_delete_missing_and_real_task(tmp_path, now):
    async def scenario():
        db = Database(tmp_path / "tasks.db")
        await db.initialize()
        await db.register_user(1, None, None, "Asia/Yekaterinburg")
        assert not await db.delete_task(1, 999)
        (task,) = await db.create_tasks(1, [NewTask(title="Лаба", deadline=now)])
        assert await db.delete_task(1, task.id)
        assert not await db.delete_task(1, task.id)
        assert await db.get_task(1, task.id) is None

    asyncio.run(scenario())


def test_demo_only_replaces_demo_for_current_user(tmp_path, now):
    async def scenario():
        db = Database(tmp_path / "tasks.db")
        await db.initialize()
        for uid in (1, 2):
            await db.register_user(uid, None, None, "Asia/Yekaterinburg")
        (real,) = await db.create_tasks(1, [NewTask(title="Реальная", deadline=now)])
        service = TaskService(db)
        first = await service.demo(1, now)
        await service.demo(2, now)
        await db.complete_task(1, first[0].id, now)
        await service.demo(1, now + timedelta(days=1))
        all_tasks = await db.list_tasks(1, active_only=False)
        assert len(all_tasks) == 5
        assert await db.get_task(1, real.id) is not None
        assert len(await db.list_tasks(2)) == 4
        assert all(
            t.deadline.date() >= (now + timedelta(days=2)).date() for t in all_tasks if t.is_demo
        )
        assert all(t.status == "active" for t in all_tasks if t.is_demo)

    asyncio.run(scenario())


def test_statistics_empty_and_mixed(tmp_path, now):
    async def scenario():
        db = Database(tmp_path / "tasks.db")
        await db.initialize()
        await db.register_user(1, None, None, "Asia/Yekaterinburg")
        service = TaskService(db)
        assert (await service.statistics(1, now)).on_time_percent is None
        tasks = await db.create_tasks(
            1,
            [
                NewTask(title="Вовремя", deadline=now + timedelta(hours=1)),
                NewTask(title="Поздно", deadline=now - timedelta(hours=1)),
                NewTask(title="Просрочено", deadline=now - timedelta(days=1)),
                NewTask(title="Активно", deadline=now + timedelta(days=1)),
            ],
        )
        await db.complete_task(1, tasks[0].id, now)
        await db.complete_task(1, tasks[1].id, now)
        stats = await service.statistics(1, now)
        assert (stats.completed, stats.on_time, stats.completed_late) == (2, 1, 1)
        assert (stats.active, stats.overdue_active, stats.on_time_percent) == (2, 1, 50)

    asyncio.run(scenario())


def test_batch_is_atomic_and_foreign_keys_enabled(tmp_path, now):
    async def scenario():
        db = Database(tmp_path / "tasks.db")
        await db.initialize()
        await db.register_user(1, None, None, "Asia/Yekaterinburg")
        async with db.connect() as connection:
            await connection.execute("""CREATE TRIGGER reject_title BEFORE INSERT ON tasks
                WHEN NEW.title = 'fail' BEGIN SELECT RAISE(ABORT, 'rejected'); END""")
        with pytest.raises(Exception, match="rejected"):
            await db.create_tasks(
                1, [NewTask(title="ok", deadline=now), NewTask(title="fail", deadline=now)]
            )
        assert await db.list_tasks(1) == []
        with pytest.raises(Exception, match="FOREIGN KEY"):
            await db.create_tasks(999, [NewTask(title="ok", deadline=now)])

    asyncio.run(scenario())
