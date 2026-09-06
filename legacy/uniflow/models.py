from dataclasses import dataclass
from datetime import datetime
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator

Title = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1, max_length=200)]
Subject = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1, max_length=100)]
Minutes = Annotated[int, Field(strict=True, ge=1, le=10080)]
Importance = Annotated[int, Field(strict=True, ge=1, le=3)]


def require_aware(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("Дата должна содержать часовой пояс")
    return value


class TaskDraft(BaseModel):
    model_config = ConfigDict(extra="forbid")

    title: Title
    subject: Subject | None = None
    deadline: datetime | None = None
    estimated_minutes: Minutes | None = None
    importance: Importance = 2

    @field_validator("deadline", mode="before")
    @classmethod
    def reject_numeric_dates(cls, value):
        if value is not None and not isinstance(value, (str, datetime)):
            raise ValueError("Ожидается ISO-дата, а не число")
        return value

    @field_validator("deadline")
    @classmethod
    def aware_deadline(cls, value):
        return require_aware(value) if value is not None else None


class NewTask(TaskDraft):
    deadline: datetime
    estimated_minutes: Minutes = 60


@dataclass(frozen=True)
class Task:
    id: int
    user_id: int
    title: str
    subject: str | None
    deadline: datetime
    estimated_minutes: int
    importance: int
    status: Literal["active", "completed"]
    created_at: datetime
    completed_at: datetime | None
    reminder_24h_sent: bool
    reminder_3h_sent: bool
    is_demo: bool
    revision: int = 0

    def __post_init__(self):
        require_aware(self.deadline)
        require_aware(self.created_at)
        if self.completed_at is not None:
            require_aware(self.completed_at)
