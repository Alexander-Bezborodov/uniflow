from datetime import timedelta

import pytest

from uniflow.services.planning_service import PlanningService


@pytest.mark.parametrize(
    ("days", "minutes", "expected"),
    [
        (0, 120, 120),
        (1, 120, 120),
        (3, 120, 40),
        (10, 1, 1),
        (3, 0, 0),
        (3, -1, 0),
        (100, 60, 5),
        (3, 1000, 334),  
        (-1, 120, 120),
    ],
)
def test_recommendation(now, days, minutes, expected):
    assert PlanningService.recommend(minutes, now + timedelta(days=days), now) == expected


def test_calendar_days_use_local_timezone(now):
    from datetime import UTC

    deadline = (now + timedelta(days=3)).astimezone(UTC)
    assert PlanningService.recommend(120, deadline, now) == 40


def test_week_never_repeats_full_estimate(now, make_task):
    task = make_task(estimated_minutes=180)
    week = PlanningService.week([task], now)
    assert [day.minutes for day in week] == [60, 60, 60, 0, 0, 0, 0]
    assert sum(day.minutes for day in week) == task.estimated_minutes
    assert week[0].minutes == sum(i.minutes for i in PlanningService.today([task], now))


def test_empty_completed_and_long_term(now, make_task):
    completed = make_task(status="completed")
    assert PlanningService.today([completed], now) == []
    assert len(PlanningService.week([], now)) == 7
    assert sum(day.minutes for day in PlanningService.week([completed], now)) == 0
    task = make_task(deadline=now + timedelta(days=100), estimated_minutes=10)
    assert sum(day.minutes for day in PlanningService.week([task], now)) == 10
