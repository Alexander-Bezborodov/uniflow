import asyncio
from collections.abc import Callable
from contextvars import ContextVar
from dataclasses import dataclass, field
from datetime import UTC, datetime
from zoneinfo import ZoneInfo

from uniflow.config import Config
from uniflow.database import Database
from uniflow.models import require_aware
from uniflow.services.llm_service import LLMService
from uniflow.services.parser_service import ParserService
from uniflow.services.task_service import TaskService

USER_TIMEZONE = ContextVar("user_timezone", default=None)


@dataclass
class AppContext:
    config: Config
    database: Database
    tasks: TaskService
    parser: ParserService
    clock: Callable[[], datetime] = field(default=lambda: datetime.now(UTC))
    jobs: dict[int, asyncio.Task] = field(default_factory=dict)
    isolation: object = None

    def now(self) -> datetime:
        timezone = USER_TIMEZONE.get() or self.config.timezone
        return require_aware(self.clock()).astimezone(ZoneInfo(timezone))

    async def user_now(self, user_id: int) -> datetime:
        user = await self.database.get_user(user_id)
        return self.now().astimezone(ZoneInfo(user.get("timezone", self.config.timezone)))

    def cancel_job(self, user_id: int):
        job = self.jobs.pop(user_id, None)
        if job:
            job.cancel()

    async def close_jobs(self):
        jobs = list(self.jobs.values())
        self.jobs.clear()
        for job in jobs:
            job.cancel()
        await asyncio.gather(*jobs, return_exceptions=True)

    @classmethod
    def create(cls, config: Config) -> "AppContext":
        database = Database(config.database_path)
        return cls(
            config,
            database,
            TaskService(database),
            ParserService(LLMService(config) if config.llm_available else None),
        )
