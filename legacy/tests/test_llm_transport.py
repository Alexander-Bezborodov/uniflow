import asyncio
import json
from datetime import timedelta

import pytest
from aiohttp import web
from aiohttp.test_utils import TestServer
from pydantic import ValidationError

import main
from uniflow.config import Config, ConfigError
from uniflow.services.llm_service import (
    ExplainedPlan,
    LLMError,
    LLMService,
    ParsedTasks,
    strict_schema,
)
from uniflow.services.parser_service import ParserService


def response_body(content):
    return {"choices": [{"finish_reason": "stop", "message": {"content": json.dumps(content)}}]}


def config(url):
    return Config(
        llm_enabled=True, llm_api_key="synthetic-test-key", llm_base_url=url, llm_model="test-model"
    )


def test_real_aiohttp_transport_to_local_fake_provider(now):
    async def scenario():
        requests = []

        async def handler(request):
            requests.append(await request.json())
            assert request.headers["Authorization"] == "Bearer synthetic-test-key"
            return web.json_response(
                response_body(
                    {
                        "tasks": [
                            {
                                "title": "Лаба",
                                "subject": None,
                                "deadline": (now + timedelta(days=1)).isoformat(),
                                "estimated_minutes": None,
                                "importance": 2,
                            }
                        ]
                    }
                )
            )

        server_app = web.Application()
        server_app.router.add_post("/v1/chat/completions", handler)
        async with TestServer(server_app) as server:
            llm = LLMService(config(str(server.make_url("/v1"))))
            tasks = await llm.parse("Лаба завтра", now, 101)
            assert tasks[0].estimated_minutes is None
            assert requests[0]["max_completion_tokens"] == 3000
            assert "101" not in json.dumps(requests[0])
            assert "timezone" not in requests[0]  

    asyncio.run(scenario())


@pytest.mark.parametrize("status", [400, 401, 403, 404, 429, 500, 502, 503])
def test_http_error_categories_and_retry_after(now, status, caplog):
    async def scenario():
        calls = 0

        async def handler(request):
            nonlocal calls
            calls += 1
            return web.Response(
                status=status, text="sensitive-provider-body", headers={"Retry-After": "120"}
            )

        server_app = web.Application()
        server_app.router.add_post("/chat/completions", handler)
        async with TestServer(server_app) as server:
            llm = LLMService(config(str(server.make_url("")).rstrip("/")))
            result = await ParserService(llm).parse("Лаба завтра 30 минут", now, user_id=101)
            assert result.source == "heuristic" and result.error == f"http_{status}"
            if status == 429:
                with pytest.raises(LLMError, match="provider_cooldown"):
                    await llm.parse("Лаба завтра", now, 202)
                assert calls == 1
        assert "sensitive-provider-body" not in caplog.text
        assert "synthetic-test-key" not in caplog.text

    asyncio.run(scenario())


@pytest.mark.parametrize(
    "body",
    [
        b"",
        b"{",
        b"{}",
        b"[]",
        b"x" * 66000,
        json.dumps(
            {"choices": [{"finish_reason": "length", "message": {"content": "{}"}}]}
        ).encode(),
        json.dumps({"choices": [{"message": {"content": None, "refusal": "no"}}]}).encode(),
        json.dumps(response_body({"tasks": [{"title": "Лаба", "extra": "ignore"}]})).encode(),
    ],
    ids=["empty", "truncated", "object", "array", "oversize", "finish-length", "refusal", "extra"],
)
def test_malformed_http_responses(now, body):
    async def scenario():
        async def handler(request):
            return web.Response(body=body)

        server_app = web.Application()
        server_app.router.add_post("/chat/completions", handler)
        async with TestServer(server_app) as server:
            llm = LLMService(config(str(server.make_url("")).rstrip("/")))
            result = await ParserService(llm).parse("Лаба завтра", now)
            assert result.source == "heuristic"

    asyncio.run(scenario())


def test_local_global_limits_and_strict_schema(monkeypatch, now):
    from test_llm import Session

    session = Session(json.dumps(response_body({"tasks": []})).encode())
    monkeypatch.setattr("uniflow.services.llm_service.ClientSession", lambda **kw: session)

    async def scenario():
        llm = LLMService(
            Config(
                llm_enabled=True,
                llm_api_key="synthetic",
                llm_base_url="https://api.groq.com/openai/v1",
                llm_model="openai/gpt-oss-20b",
            )
        )
        await llm.parse("Лаба", now, 1)
        assert session.request[1]["json"]["response_format"]["type"] == "json_schema"
        with pytest.raises(LLMError, match="local_rate_limit"):
            await llm.parse("Лаба", now, 1)
        await llm.parse("Лаба", now, 2)
        with pytest.raises(LLMError, match="local_rate_limit"):
            await llm.parse("Лаба", now, 3)

    asyncio.run(scenario())
    for schema in (strict_schema(ParsedTasks), strict_schema(ExplainedPlan)):

        def check(node):
            if isinstance(node, dict):
                if node.get("type") == "object":
                    assert set(node["required"]) == set(node["properties"])
                    assert node["additionalProperties"] is False
                for value in node.values():
                    check(value)
            elif isinstance(node, list):
                for value in node:
                    check(value)

        check(schema)


@pytest.mark.parametrize(
    "change",
    [
        {"task_ref": 2},
        {"minutes": 31},
        {"deadline": "2029-01-01"},
        {"reason": "volume"},
    ],
)
def test_explanation_rejects_invented_facts(monkeypatch, now, change):
    from test_llm import Session

    fact = {
        "task_ref": 1,
        "minutes": 30,
        "deadline": now.isoformat(),
        "allowed_reasons": ["deadline"],
    }
    step = {
        "task_ref": 1,
        "minutes": 30,
        "deadline": now.isoformat(),
        "reason": "deadline",
    } | change
    session = Session(json.dumps(response_body({"steps": [step]})).encode())
    monkeypatch.setattr("uniflow.services.llm_service.ClientSession", lambda **kw: session)

    async def scenario():
        llm = LLMService(config("https://provider.invalid/v1"))
        with pytest.raises(LLMError, match="inconsistent_explanation"):
            await llm.explain([fact], now, 101)

    asyncio.run(scenario())


def test_check_llm_never_accepts_fallback(monkeypatch, now, capsys):
    with pytest.raises(ConfigError):
        asyncio.run(main.check_llm(Config()))

    async def failing(*args, **kwargs):
        raise LLMError("http_401")

    monkeypatch.setattr(LLMService, "parse", failing)
    with pytest.raises(LLMError, match="http_401"):
        asyncio.run(main.check_llm(config("https://provider.invalid/v1")))
    assert "OK:" not in capsys.readouterr().out


@pytest.mark.parametrize(
    "raw",
    [
        {"title": "Лаба", "subject": False},
        {"title": "Лаба", "estimated_minutes": 1.5},
        {"title": "Лаба", "estimated_minutes": True},
        {"title": "Лаба", "importance": -1},
        {"title": "Лаба", "deadline": "2026-02-30T00:00:00Z"},
    ],
)
def test_server_schema_rejects_types_and_dates(raw):
    with pytest.raises(ValidationError):
        ParsedTasks.model_validate_json(json.dumps({"tasks": [raw]}))


def test_connection_failure_fallback(monkeypatch, now):
    from aiohttp import ClientConnectionError
    from test_llm import Session

    session = Session(b"", error=ClientConnectionError("SENSITIVE-error-body"))
    monkeypatch.setattr("uniflow.services.llm_service.ClientSession", lambda **kw: session)
    result = asyncio.run(
        ParserService(LLMService(config("https://provider.invalid/v1"))).parse("Лаба завтра", now)
    )
    assert result.error == "connection" and result.source == "heuristic"


def test_reordered_provider_tasks_keep_their_own_deadlines(monkeypatch, now):
    from test_llm import Session

    tasks = [
        {
            "title": title,
            "subject": None,
            "deadline": None,
            "estimated_minutes": None,
            "importance": 2,
        }
        for title in ("Подготовить презентацию", "Сдать эссе")
    ]
    session = Session(json.dumps(response_body({"tasks": tasks})).encode())
    monkeypatch.setattr("uniflow.services.llm_service.ClientSession", lambda **kw: session)
    result = asyncio.run(
        ParserService(LLMService(config("https://provider.invalid/v1"))).parse(
            "Сдать эссе завтра 30 минут; подготовить презентацию через 3 дня 90 минут", now
        )
    )
    assert result.source == "llm"
    assert [task.deadline.day for task in result.tasks] == [8, 6]
    assert [task.estimated_minutes for task in result.tasks] == [90, 30]
