from datetime import timedelta

import pytest

from uniflow.services.priority_service import Priority, PriorityService


@pytest.mark.parametrize(
    ("hours", "expected"),
    [
        (-1, Priority.CRITICAL),
        (0, Priority.CRITICAL),
        (6, Priority.VERY_HIGH),
        (24, Priority.VERY_HIGH),
        (24.1, Priority.HIGH),
        (72, Priority.HIGH),
        (72.1, Priority.MEDIUM),
        (168, Priority.MEDIUM),
        (240, Priority.LOW),
    ],
)
def test_deadline_buckets(now, hours, expected):
    assert PriorityService.calculate(now + timedelta(hours=hours), 2, 60, now) == expected


def test_importance_and_duration_raise_at_most_one_level(now):
    deadline = now + timedelta(days=10)
    assert PriorityService.calculate(deadline, 1, 60, now) == Priority.LOW
    assert PriorityService.calculate(deadline, 3, 60, now) == Priority.MEDIUM
    assert PriorityService.calculate(deadline, 2, 180, now) == Priority.MEDIUM
    assert PriorityService.calculate(deadline, 3, 180, now) == Priority.MEDIUM


def test_overdue_first_and_tie_break_by_deadline(now, make_task):
    tasks = [
        make_task(id=1, deadline=now + timedelta(days=2)),
        make_task(id=2, deadline=now - timedelta(days=1)),
        make_task(id=3, deadline=now + timedelta(hours=25)),
    ]
    assert [t.id for t in PriorityService.sort(tasks, now)] == [2, 3, 1]


def test_invalid_and_naive_inputs(now):
    with pytest.raises(ValueError):
        PriorityService.calculate(now, 5, 60, now)
    with pytest.raises(ValueError):
        PriorityService.calculate(now.replace(tzinfo=None), 2, 60, now)
