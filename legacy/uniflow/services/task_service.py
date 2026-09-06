from dataclasses import dataclass
from datetime import datetime, timedelta

from uniflow.database import Database
from uniflow.models import NewTask


@dataclass(frozen=True)
class Statistics:
    completed: int
    on_time: int
    completed_late: int
    active: int
    overdue_active: int

    @property
    def on_time_percent(self) -> int | None:
        return round(100 * self.on_time / self.completed) if self.completed else None


class TaskService:
    def __init__(self, database: Database):
        self.database = database

    async def statistics(self, user_id: int, now: datetime) -> Statistics:
        tasks = await self.database.list_tasks(user_id, active_only=False)
        completed = [t for t in tasks if t.status == "completed"]
        active = [t for t in tasks if t.status == "active"]
        on_time = sum(
            t.completed_at is not None and t.completed_at <= t.deadline for t in completed
        )
        return Statistics(
            len(completed),
            on_time,
            len(completed) - on_time,
            len(active),
            sum(t.deadline <= now for t in active),
        )

    async def demo(self, user_id: int, now: datetime):
        examples = [
            ("Лабораторная по Java", "Программирование", 1, 120, 3),
            ("Подготовка к контрольной по математике", "Математика", 3, 180, 3),
            ("Домашнее задание по английскому", "Английский", 5, 60, 2),
            ("Прочитать главу по истории", "История", 7, 45, 1),
        ]
        tasks = [
            NewTask(
                title=title,
                subject=subject,
                deadline=(now + timedelta(days=days)).replace(
                    hour=23, minute=59, second=0, microsecond=0
                ),
                estimated_minutes=minutes,
                importance=importance,
            )
            for title, subject, days, minutes, importance in examples
        ]
        return await self.database.create_tasks(user_id, tasks, replace_demo=True, now=now)
