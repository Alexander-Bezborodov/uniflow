import asyncio
from datetime import UTC, datetime, timedelta
from zoneinfo import ZoneInfo

import pytest
from aiogram.methods import EditMessageText
from test_handlers import harness

from uniflow.config import Config
from uniflow.context import AppContext
from uniflow.models import NewTask
from uniflow.services.planning_service import PlanningService
from uniflow.utils.dates import parse_deadline


@pytest.mark.parametrize(
    ("instant", "zone", "day"),
    [
        ("2026-09-05T19:30:00+00:00", "Europe/Moscow", 5),
        ("2026-09-05T19:30:00+00:00", "Asia/Yekaterinburg", 6),
        ("2026-09-05T20:59:00+00:00", "Europe/Moscow", 5),
        ("2026-09-05T21:00:00+00:00", "Europe/Moscow", 6),
        ("2026-09-05T23:59:00+00:00", "Europe/Moscow", 6),
        ("2026-09-06T00:00:00+00:00", "Europe/Moscow", 6),
        ("2026-12-31T21:00:00+00:00", "Europe/Moscow", 1),
        ("2026-09-30T21:00:00+00:00", "Europe/Moscow", 1),
    ],
)
def test_headers_follow_user_time(tmp_path, instant, zone, day):
    async def scenario():
        now = datetime.fromisoformat(instant)
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.app.database.set_preferences(101, timezone=zone)
            await h.send("/today")
            assert f"\n{day} " in h.session.calls[-1].text
            assert zone in h.session.calls[-1].text

    asyncio.run(scenario())


def test_settings_two_users_restart_and_absolute_deadlines(tmp_path):
    async def scenario():
        now = datetime(2026, 9, 5, 19, 30, tzinfo=UTC)
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.click(h.button("Москва"))
            assert "05.09.2026 22:30" in h.text
            await h.click(h.button("Выключить напоминания"))
            await h.send("/start", 202)
            await h.click(h.button("Екатеринбург"), 202)
            await h.send("/today", 202)
            assert "6 сентября · Asia/Yekaterinburg" in h.text
            await h.send("Лаба завтра в 10:00 30 минут")
            await h.click(h.button("✅ Добавить все"))
            task = (await h.app.database.list_tasks(101))[0]
            assert task.deadline == datetime(2026, 9, 6, 7, tzinfo=UTC)
            await h.send("/settings")
            await h.click(h.button("Другой IANA"))
            await h.send("Invalid/Nowhere")
            assert "Неизвестный пояс" in h.text
            await h.send("Asia/Yekaterinburg")
            await h.send("/start")
            user = await h.app.database.get_user(101)
            assert user["timezone"] == "Asia/Yekaterinburg"
            assert user["reminders_disabled"] and user["timezone_confirmed"]
            assert (await h.app.database.get_task(101, task.id)).deadline == task.deadline
            restarted = AppContext.create(Config(database_path=h.app.database.path, timezone="UTC"))
            restarted.clock = lambda: now
            await restarted.database.initialize()
            assert (await restarted.user_now(101)).day == 6
            assert (await restarted.database.get_user(101))["reminders_disabled"]
            assert (await restarted.database.get_task(101, task.id)).deadline == task.deadline

    asyncio.run(scenario())


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("29.02", "2028-02-29"),
        ("29 февраля 2028", "2028-02-29"),
        ("29.02.2027", None),
        ("31 февраля", None),
        ("31.04", None),
        ("10/09", None),
        ("10.09.20", None),
        ("2026-02-30", None),
    ],
)
def test_strict_calendar_and_leap_dates(now, text, expected):
    parsed = parse_deadline(text, now)
    assert (parsed.date().isoformat() if parsed else None) == expected


def test_dst_gap_fold_and_weekday_rules():
    spring = datetime(2026, 3, 28, 12, tzinfo=ZoneInfo("Europe/Berlin"))
    assert parse_deadline("завтра в 02:30", spring) is None
    autumn = datetime(2026, 10, 24, 12, tzinfo=ZoneInfo("Europe/Berlin"))
    result = parse_deadline("завтра в 02:30", autumn)
    assert result.fold == 0 and result.astimezone(UTC).hour == 0
    friday = datetime(2026, 9, 11, 12, tzinfo=ZoneInfo("Europe/Moscow"))
    assert parse_deadline("в пятницу", friday).day == 11
    assert parse_deadline("в следующую пятницу", friday).day == 18
    assert parse_deadline("в пятницу в 10:17", friday).minute == 17


def test_refresh_rollover_and_due_filter(tmp_path):
    async def scenario():
        now = datetime(2026, 9, 5, 23, 59, tzinfo=ZoneInfo("Europe/Moscow"))
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.click(h.button("Москва"))
            await h.app.database.create_tasks(
                101,
                [
                    NewTask(
                        title="Завтра", deadline=now + timedelta(hours=1), estimated_minutes=90
                    ),
                    NewTask(title="Дальше", deadline=now + timedelta(days=4), estimated_minutes=90),
                ],
            )
            await h.send("/today")
            refresh = h.button("🔄")
            assert "Дедлайн: завтра, 6 сентября" in h.session.calls[-1].text
            h.app.clock = lambda: now + timedelta(minutes=1)
            await h.click(refresh)
            edits = [c for c in h.session.calls if isinstance(c, EditMessageText)]
            assert "6 сентября · Europe/Moscow" in edits[-1].text
            assert "Дедлайн: сегодня, 6 сентября" in edits[-1].text
            await h.click(h.button("Дедлайны сегодня"))
            assert "Дальше" not in h.session.calls[-1].text
            assert "Выбранные задачи: 1 ч 30 мин" in h.session.calls[-1].text
            await h.send("/week")
            assert "Europe/Moscow" in h.session.calls[-1].text

    asyncio.run(scenario())


def test_week_conserves_work_before_deadline(now, make_task):
    for minutes in (1, 5, 90, 1000, 10080):
        for days in (0, 1, 2, 3, 6):
            task = make_task(estimated_minutes=minutes, deadline=now + timedelta(days=days))
            week = PlanningService.week([task], now)
            assert sum(d.minutes for d in week) == minutes
            assert all(d.minutes == 0 for d in week[max(1, days) :])
            assert week[0].minutes == sum(i.minutes for i in PlanningService.today([task], now))
