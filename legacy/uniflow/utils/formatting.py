import re
from datetime import datetime
from html import escape, unescape

from uniflow.models import Task
from uniflow.services.priority_service import PriorityService
from uniflow.utils.dates import date_label


def duration(minutes: int) -> str:
    hours, rest = divmod(minutes, 60)
    return " ".join(
        part
        for part in (f"{hours} ч" if hours else "", f"{rest} мин" if rest or not hours else "")
        if part
    )


def task_card(task: Task, now: datetime) -> str:
    priority = PriorityService.calculate(
        task.deadline, task.importance, task.estimated_minutes, now
    )
    return (
        ("🚨 <b>ПРОСРОЧЕНО</b>\n" if task.deadline <= now and task.status == "active" else "")
        + f"<b>{escape(task.title)}</b>"
        + (" 🎓 демо" if task.is_demo else "")
        + (f"\nПредмет: {escape(task.subject)}" if task.subject else "")
        + f"\n📅 Дедлайн: {date_label(task.deadline, now)}"
        + f"\n⏱ Оценка всего: {duration(task.estimated_minutes)}"
        + f"\n⚡ Приоритет: {priority.label}"
    )


def text_units(html: str) -> int:
    
    text = unescape(re.sub(r"<[^>]+>", "", html))
    return len(text.encode("utf-16-le")) // 2
