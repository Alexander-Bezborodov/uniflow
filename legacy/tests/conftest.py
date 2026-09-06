from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

import pytest

from uniflow.models import Task


@pytest.fixture
def now():
    return datetime(2026, 9, 5, 12, 0, tzinfo=ZoneInfo("Asia/Yekaterinburg"))


@pytest.fixture
def make_task(now):
    def factory(**kwargs):
        values = dict(
            id=1,
            user_id=100,
            title="Лаба",
            subject=None,
            deadline=now + timedelta(days=3),
            estimated_minutes=120,
            importance=2,
            status="active",
            created_at=now,
            completed_at=None,
            reminder_24h_sent=False,
            reminder_3h_sent=False,
            is_demo=False,
        )
        values.update(kwargs)
        return Task(**values)

    return factory
