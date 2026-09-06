import asyncio
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import UTC, datetime, timedelta
from pathlib import Path
from weakref import WeakValueDictionary
from zoneinfo import ZoneInfo

import aiosqlite

from uniflow.models import NewTask, Task
from uniflow.utils.dates import utc_string

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    telegram_id INTEGER PRIMARY KEY,
    username TEXT,
    full_name TEXT,
    timezone TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    reminders_disabled INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(telegram_id),
    title TEXT NOT NULL CHECK(length(trim(title)) BETWEEN 1 AND 200),
    subject TEXT,
    deadline DATETIME NOT NULL,
    estimated_minutes INTEGER NOT NULL DEFAULT 60 CHECK(estimated_minutes BETWEEN 1 AND 10080),
    importance INTEGER NOT NULL DEFAULT 2 CHECK(importance BETWEEN 1 AND 3),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'completed')),
    created_at DATETIME NOT NULL,
    completed_at DATETIME,
    reminder_24h_sent INTEGER NOT NULL DEFAULT 0,
    reminder_3h_sent INTEGER NOT NULL DEFAULT 0,
    is_demo INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_tasks_user_status ON tasks(user_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_deadline ON tasks(status, deadline);
"""


def task_from_row(row: aiosqlite.Row) -> Task:
    values = dict(row)
    for key in ("deadline", "created_at", "completed_at"):
        values[key] = datetime.fromisoformat(values[key]) if values[key] else None
    for key in ("reminder_24h_sent", "reminder_3h_sent", "is_demo"):
        values[key] = bool(values[key])
    return Task(**values)


class Database:
    def __init__(self, path: Path):
        self.path = path
        self._user_locks = WeakValueDictionary()

    def user_lock(self, user_id: int):
        lock = self._user_locks.get(user_id)
        if lock is None:
            lock = asyncio.Lock()
            self._user_locks[user_id] = lock
        return lock

    @asynccontextmanager
    async def connect(self) -> AsyncIterator[aiosqlite.Connection]:
        
        async with aiosqlite.connect(self.path, timeout=10) as connection:
            connection.row_factory = aiosqlite.Row
            await connection.execute("PRAGMA foreign_keys = ON")
            try:
                yield connection
                await connection.commit()
            except BaseException:
                await connection.rollback()
                raise

    async def initialize(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        async with self.connect() as db:
            version = (await (await db.execute("PRAGMA user_version")).fetchone())[0]
            if version > 1:
                raise ValueError("База создана более новой версией UniFlow")
            await db.execute("PRAGMA journal_mode = WAL")
            await db.executescript(SCHEMA)
            await db.execute("BEGIN IMMEDIATE")
            version = (await (await db.execute("PRAGMA user_version")).fetchone())[0]
            if version > 1:
                raise ValueError("База создана более новой версией UniFlow")
            if version == 0:
                for definition in (
                    "timezone_confirmed INTEGER NOT NULL DEFAULT 0",
                    "telegram_blocked INTEGER NOT NULL DEFAULT 0",
                    "ai_consent INTEGER NOT NULL DEFAULT 0",
                ):
                    await db.execute("ALTER TABLE users ADD COLUMN " + definition)
                await db.execute("ALTER TABLE tasks ADD COLUMN revision INTEGER NOT NULL DEFAULT 0")
                await db.execute("PRAGMA user_version = 1")

    async def register_user(
        self, telegram_id: int, username: str | None, full_name: str | None, timezone: str
    ):
        async with self.connect() as db:
            await db.execute(
                """INSERT INTO users(telegram_id, username, full_name, timezone, created_at)
                VALUES (?, ?, ?, ?, ?) ON CONFLICT(telegram_id) DO UPDATE SET
                username=excluded.username, full_name=excluded.full_name, telegram_blocked=0""",
                (telegram_id, username, full_name, timezone, utc_string(datetime.now(UTC))),
            )

    async def get_user(self, user_id: int) -> dict:
        async with self.connect() as db:
            row = await (
                await db.execute("SELECT * FROM users WHERE telegram_id=?", (user_id,))
            ).fetchone()
            return dict(row) if row else {}

    async def set_preferences(self, user_id: int, **values):
        allowed = {"timezone", "timezone_confirmed", "reminders_disabled", "ai_consent"}
        if not values or not values.keys() <= allowed:
            raise ValueError("Недопустимая настройка")
        if "timezone" in values:
            ZoneInfo(values["timezone"])
        if any(v not in (0, 1) for k, v in values.items() if k != "timezone"):
            raise ValueError("Недопустимое значение настройки")
        fields = ", ".join(f"{key}=?" for key in values)
        async with self.user_lock(user_id), self.connect() as db:
            await db.execute(
                f"UPDATE users SET {fields} WHERE telegram_id=?", (*values.values(), user_id)
            )

    async def update_task(
        self, user_id: int, task_id: int, revision: int, field: str, value, now: datetime
    ) -> bool:
        if field not in {"title", "subject", "deadline", "estimated_minutes", "importance"}:
            raise ValueError("Недопустимое поле")
        async with self.user_lock(user_id), self.connect() as db:
            await db.execute("BEGIN IMMEDIATE")
            row = await (
                await db.execute(
                    "SELECT * FROM tasks WHERE user_id=? AND id=? "
                    "AND status='active' AND revision=?",
                    (user_id, task_id, revision),
                )
            ).fetchone()
            if row is None:
                return False
            old = task_from_row(row)
            draft = NewTask.model_validate(
                {
                    key: value if key == field else getattr(old, key)
                    for key in ("title", "subject", "deadline", "estimated_minutes", "importance")
                }
            )
            reset = ""
            if field == "deadline":
                if draft.deadline.timestamp() <= now.timestamp():
                    raise ValueError("Дедлайн уже прошёл")
                value = utc_string(draft.deadline)
                reset = ", reminder_24h_sent=0, reminder_3h_sent=0"
            else:
                value = getattr(draft, field)
            await db.execute(
                f"UPDATE tasks SET {field}=?, revision=revision+1{reset} WHERE user_id=? AND id=?",
                (value, user_id, task_id),
            )
            return True

    async def create_tasks(
        self,
        user_id: int,
        tasks: list[NewTask],
        *,
        replace_demo: bool = False,
        now: datetime | None = None,
    ) -> list[Task]:
        
        validated = [NewTask.model_validate(t.model_dump()) for t in tasks]
        created = utc_string(now or datetime.now(UTC))
        result = []
        async with self.user_lock(user_id), self.connect() as db:
            await db.execute("BEGIN IMMEDIATE")
            if replace_demo:
                await db.execute("DELETE FROM tasks WHERE user_id=? AND is_demo=1", (user_id,))
            for task in validated:
                async with db.execute(
                    """INSERT INTO tasks(user_id, title, subject, deadline, estimated_minutes,
                    importance, created_at, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
                    (
                        user_id,
                        task.title,
                        task.subject,
                        utc_string(task.deadline),
                        task.estimated_minutes,
                        task.importance,
                        created,
                        int(replace_demo),
                    ),
                ) as cursor:
                    task_id = cursor.lastrowid
                async with db.execute(
                    "SELECT * FROM tasks WHERE id=? AND user_id=?", (task_id, user_id)
                ) as cursor:
                    result.append(task_from_row(await cursor.fetchone()))
        return result

    async def list_tasks(self, user_id: int, *, active_only: bool = True) -> list[Task]:
        query = "SELECT * FROM tasks WHERE user_id=?"
        if active_only:
            query += " AND status='active'"
        async with self.connect() as db:
            async with db.execute(query + " ORDER BY deadline, id", (user_id,)) as cursor:
                return [task_from_row(row) for row in await cursor.fetchall()]

    async def get_task(self, user_id: int, task_id: int) -> Task | None:
        async with self.connect() as db:
            async with db.execute(
                "SELECT * FROM tasks WHERE user_id=? AND id=?", (user_id, task_id)
            ) as cursor:
                row = await cursor.fetchone()
                return task_from_row(row) if row else None

    async def complete_task(self, user_id: int, task_id: int, now: datetime) -> bool:
        async with self.user_lock(user_id), self.connect() as db:
            async with db.execute(
                """UPDATE tasks SET status='completed', completed_at=?
                WHERE user_id=? AND id=? AND status='active'""",
                (utc_string(now), user_id, task_id),
            ) as cursor:
                return cursor.rowcount == 1

    async def delete_task(self, user_id: int, task_id: int) -> bool:
        async with self.user_lock(user_id), self.connect() as db:
            async with db.execute(
                "DELETE FROM tasks WHERE user_id=? AND id=? AND status='active'",
                (user_id, task_id),
            ) as cursor:
                return cursor.rowcount == 1

    async def reminder_candidates(self, now: datetime) -> list[tuple[Task, str]]:
        async with self.connect() as db:
            async with db.execute(
                """SELECT tasks.*, users.timezone AS user_timezone FROM tasks
                JOIN users ON users.telegram_id=tasks.user_id
                WHERE status='active' AND deadline>? AND deadline<=?
                AND reminder_3h_sent=0 AND reminders_disabled=0 AND telegram_blocked=0
                AND (reminder_24h_sent=0 OR deadline<=?) ORDER BY deadline""",
                (
                    utc_string(now),
                    utc_string(now + timedelta(hours=24)),
                    utc_string(now + timedelta(hours=3)),
                ),
            ) as cursor:
                result = []
                for row in await cursor.fetchall():
                    data = dict(row)
                    timezone = data.pop("user_timezone")
                    result.append((task_from_row(data), timezone))
                return result

    async def mark_reminder(self, user_id: int, task_id: int, hours: int, revision: int) -> bool:
        column = self._reminder_column(hours)
        async with self.connect() as db:
            async with db.execute(
                f"UPDATE tasks SET {column}=1 WHERE user_id=? AND id=? "
                f"AND status='active' AND {column}=0 AND reminder_3h_sent=0 AND revision=?",
                (user_id, task_id, revision),
            ) as cursor:
                return cursor.rowcount == 1

    async def release_reminder(self, user_id: int, task_id: int, hours: int):
        column = self._reminder_column(hours)
        async with self.connect() as db:
            await db.execute(
                f"UPDATE tasks SET {column}=0 WHERE user_id=? AND id=?", (user_id, task_id)
            )

    async def mark_blocked(self, user_id: int):
        async with self.connect() as db:
            await db.execute("UPDATE users SET telegram_blocked=1 WHERE telegram_id=?", (user_id,))

    async def disable_reminders(self, user_id: int):
        async with self.connect() as db:
            await db.execute(
                "UPDATE users SET reminders_disabled=1 WHERE telegram_id=?", (user_id,)
            )

    @staticmethod
    def _reminder_column(hours: int) -> str:
        if hours not in (3, 24):
            raise ValueError("Разрешены только интервалы 3 и 24 часа")
        return f"reminder_{hours}h_sent"
