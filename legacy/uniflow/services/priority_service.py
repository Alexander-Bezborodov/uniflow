from datetime import datetime
from enum import IntEnum

from uniflow.models import Task, require_aware


class Priority(IntEnum):
    LOW = 1
    MEDIUM = 2
    HIGH = 3
    VERY_HIGH = 4
    CRITICAL = 5

    @property
    def label(self) -> str:
        return {
            self.LOW: "🟢 низкий",
            self.MEDIUM: "🟡 средний",
            self.HIGH: "🟠 высокий",
            self.VERY_HIGH: "🔴 очень высокий",
            self.CRITICAL: "🔴 критический",
        }[self]


class PriorityService:
    @staticmethod
    def calculate(
        deadline: datetime, importance: int, estimated_minutes: int, now: datetime
    ) -> Priority:
        require_aware(deadline)
        require_aware(now)
        if importance not in (1, 2, 3) or isinstance(importance, bool):
            raise ValueError("Важность должна быть от 1 до 3")
        if estimated_minutes < 0:
            raise ValueError("Длительность не может быть отрицательной")
        hours = (deadline.timestamp() - now.timestamp()) / 3600
        if hours <= 0:
            return Priority.CRITICAL
        base = (
            Priority.VERY_HIGH
            if hours <= 24
            else Priority.HIGH
            if hours <= 72
            else Priority.MEDIUM
            if hours <= 168
            else Priority.LOW
        )
        
        bonus = importance == 3 or estimated_minutes >= 180
        return Priority(min(Priority.VERY_HIGH, base + int(bonus)))

    @classmethod
    def sort(cls, tasks: list[Task], now: datetime) -> list[Task]:
        return sorted(
            tasks,
            key=lambda t: (
                -cls.calculate(t.deadline, t.importance, t.estimated_minutes, now),
                t.deadline,
                -t.importance,
                -t.estimated_minutes,
                t.id,
            ),
        )
