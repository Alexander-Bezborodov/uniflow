import math
from dataclasses import dataclass
from datetime import date, datetime, timedelta

from uniflow.models import Task, require_aware
from uniflow.services.priority_service import Priority, PriorityService


@dataclass(frozen=True)
class PlanItem:
    task: Task
    minutes: int
    priority: Priority


@dataclass(frozen=True)
class DayLoad:
    day: date
    minutes: int


class PlanningService:
    @staticmethod
    def recommend(estimated_minutes: int, deadline: datetime, now: datetime) -> int:
        require_aware(deadline)
        require_aware(now)
        if estimated_minutes <= 0:
            return 0
        days_left = max(1, (deadline.astimezone(now.tzinfo).date() - now.date()).days)
        hours = (deadline.timestamp() - now.timestamp()) / 3600
        if hours <= 24 or days_left == 1:
            return estimated_minutes
        normal = math.ceil(estimated_minutes / days_left)
        
        return min(estimated_minutes, max(5, normal))

    @classmethod
    def today(cls, tasks: list[Task], now: datetime) -> list[PlanItem]:
        active = [t for t in tasks if t.status == "active"]
        return [
            PlanItem(
                t,
                cls.recommend(t.estimated_minutes, t.deadline, now),
                PriorityService.calculate(t.deadline, t.importance, t.estimated_minutes, now),
            )
            for t in PriorityService.sort(active, now)
        ]

    @classmethod
    def week(cls, tasks: list[Task], now: datetime) -> list[DayLoad]:
        totals = [0] * 7
        for task in tasks:
            if task.status != "active":
                continue
            remaining = task.estimated_minutes
            for offset in range(7):
                if remaining <= 0:
                    break
                current = now + timedelta(days=offset)
                minutes = cls.recommend(remaining, task.deadline, current)
                totals[offset] += minutes
                remaining -= minutes
        return [
            DayLoad(now.date() + timedelta(days=i), minutes) for i, minutes in enumerate(totals)
        ]
