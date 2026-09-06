import asyncio
from datetime import datetime

import pytest
from pydantic import ValidationError

from uniflow.models import NewTask
from uniflow.services.llm_service import ParsedTasks
from uniflow.services.parser_service import ParserService, heuristic_parse
from uniflow.utils.dates import parse_deadline, parse_duration


@pytest.mark.parametrize(
    ("text", "day"),
    [
        ("завтра", 6),
        ("послезавтра", 7),
        ("через 3 дня", 8),
        ("10.09", 10),
        ("10.09.2026", 10),
        ("10 сентября", 10),
        ("в пятницу", 11),
        ("к следующему вторнику", 8),
        ("2026-09-10", 10),
    ],
)
def test_russian_deadlines(now, text, day):
    result = parse_deadline(text, now)
    assert result.day == day and result.month == 9
    assert (result.hour, result.minute) == (23, 59)
    assert result.utcoffset() == now.utcoffset()


@pytest.mark.parametrize(
    "text",
    [
        "непонятно",
        "31.02",
        "99.99.2026",
        "10.09 в 25:99",
        "10.09.20",
        "возможно завтра",
        "15",
        "через 999999999 дней",
    ],
)
def test_invalid_date(now, text):
    assert parse_deadline(text, now) is None


def test_explicit_time_and_new_year(now):
    assert parse_deadline("завтра в 18:30", now).hour == 18
    assert parse_deadline("01.01", now).year == 2027
    assert parse_deadline("01.01.2026", now).year == 2026


@pytest.mark.parametrize(
    ("text", "minutes"),
    [
        ("2 часа", 120),
        ("90 минут", 90),
        ("1.5 часа", 90),
        ("1,5 часа", 90),
        ("1 час", 60),
        ("примерно час", 60),
        ("полчаса", 30),
        ("30 минут", 30),
        ("120", 120),
        ("1 час 30 минут", 90),
        ("2 ч", 120),
        ("полтора часа", 90),
    ],
)
def test_duration(text, minutes):
    assert parse_duration(text) == minutes


@pytest.mark.parametrize("text", ["0 минут", "-2 часа", "много", "10081 минут", "час ерунда"])
def test_invalid_duration(text):
    assert parse_duration(text) is None


@pytest.mark.parametrize(
    ("text", "day", "minutes"),
    [
        ("Лаба по Java до пятницы 2 часа", 11, 120),
        ("Сделать дз по матану завтра, примерно час", 6, 60),
        ("Контрольная по английскому 15 сентября, подготовка 90 минут", 15, 90),
        ("Реферат через 3 дня 30 минут", 8, 30),
    ],
)
def test_quick_input(now, text, day, minutes):
    (task,) = heuristic_parse(text, now)
    assert task.deadline.day == day
    assert task.estimated_minutes == minutes
    assert "примерно" not in task.title and "подготовка 90" not in task.title


def test_teacher_message_shared_deadline(now):
    tasks = heuristic_parse(
        "Коллеги, к следующему вторнику необходимо выполнить лабораторную "
        "работу №3, ознакомиться с главами 4-5 и подготовиться к контрольной работе.",
        now,
    )
    assert [t.title for t in tasks] == [
        "Лабораторная работа №3",
        "Прочитать главы 4-5",
        "Подготовиться к контрольной работе",
    ]
    assert {t.deadline.day for t in tasks} == {8}
    assert all(t.estimated_minutes is None for t in tasks)


def test_invalid_text_and_missing_fields(now):
    assert heuristic_parse("абракадабра", now) == []
    assert heuristic_parse("", now) == []
    (task,) = heuristic_parse("Лаба по Java", now)
    assert task.deadline is None and task.estimated_minutes is None


def test_llm_failure_falls_back(now, caplog):
    class BrokenLLM:
        async def parse(self, text, now):
            raise RuntimeError("SENSITIVE-provider-data")

    result = asyncio.run(ParserService(BrokenLLM()).parse("Лаба завтра 2 часа", now))
    assert result.source == "heuristic"
    assert result.tasks[0].estimated_minutes == 120
    assert "SENSITIVE" not in caplog.text


@pytest.mark.parametrize(
    "raw",
    [
        "not json",
        '{"tasks":[{"title":""}]}',
        '{"tasks":[{"title":"Лаба","estimated_minutes":0}]}',
        '{"tasks":[{"title":"Лаба","estimated_minutes":"60"}]}',
        '{"tasks":[{"title":"Лаба","importance":true}]}',
        '{"tasks":[{"title":"Лаба","importance":4}]}',
        '{"tasks":[{"title":"Лаба","deadline":"2026-09-10T18:00:00"}]}',
        '{"tasks":[{"title":"Лаба","deadline":1750000000}]}',
        '{"tasks":[{"title":"Лаба","execute":"rm"}]}',
        '{"tasks":[], "instructions":"ignore"}',
    ],
)
def test_strict_llm_json_validation(raw):
    with pytest.raises(ValidationError):
        ParsedTasks.model_validate_json(raw)


def test_new_task_requires_valid_deadline_and_minutes(now):
    for values in ({"deadline": None}, {"estimated_minutes": -1}, {"importance": 0}):
        data = {"title": "Лаба", "deadline": now, "estimated_minutes": 60} | values
        with pytest.raises(ValidationError):
            NewTask(**data)
    with pytest.raises(ValidationError):
        NewTask(title="Лаба", deadline=datetime(2026, 9, 10))
