import asyncio
import logging
from dataclasses import replace
from unittest.mock import AsyncMock

import pytest

import main
from uniflow.config import Config, ConfigError
from uniflow.context import AppContext
from uniflow.logging_setup import SecretFormatter


def test_optional_llm_missing_key_never_prevents_start(tmp_path):
    path = tmp_path / ".env"
    path.write_text("LLM_ENABLED=true\nTIMEZONE=Asia/Yekaterinburg\n", encoding="utf-8")
    config = Config.load(path, require_token=False)
    assert not config.llm_available
    assert AppContext.create(config).parser.llm is None
    path.write_text("LLM_ENABLED=false\n", encoding="utf-8")
    assert not Config.load(path, require_token=False).llm_available


def test_config_errors_are_actionable_and_do_not_echo_input(tmp_path):
    path = tmp_path / ".env"
    path.write_text("", encoding="utf-8")
    with pytest.raises(ConfigError, match="BOT_TOKEN"):
        Config.load(path)
    path.write_text("TIMEZONE=invalid-secret-value\n", encoding="utf-8")
    with pytest.raises(ConfigError, match="TIMEZONE") as exc:
        Config.load(path)
    assert "invalid-secret-value" not in str(exc.value)
    path.write_text("LLM_ENABLED=maybe\n", encoding="utf-8")
    with pytest.raises(ConfigError, match="LLM_ENABLED"):
        Config.load(path, require_token=False)


def test_logging_redacts_secrets_and_repr_hides_keys():
    config = Config(bot_token="fake-bot-secret", llm_api_key="fake-llm-secret")
    assert "fake-" not in repr(config)
    formatter = SecretFormatter((config.bot_token, config.llm_api_key))
    record = logging.LogRecord(
        "test", logging.ERROR, "", 1, "URL %s key %s", (config.bot_token, config.llm_api_key), None
    )
    rendered = formatter.format(record)
    assert "fake-" not in rendered and rendered.count("[СКРЫТО]") == 2


def test_startup_check_does_not_touch_real_database(tmp_path, capsys):
    path = tmp_path / "real.db"
    path.write_text("do not touch", encoding="utf-8")
    asyncio.run(main.check_startup(Config(database_path=path)))
    assert path.read_text() == "do not touch"
    assert "OK:" in capsys.readouterr().out


def test_run_starts_reminders_and_closes_everything(tmp_path, monkeypatch):
    async def scenario():
        from aiogram import Bot
        from test_handlers import FakeSession

        session = FakeSession()
        bot = Bot("999999:" + "offline" * 6, session=session)
        config = Config(database_path=tmp_path / "startup.db")
        monkeypatch.setattr(main, "Bot", lambda *args, **kwargs: bot)
        original_factory = main.create_dispatcher
        ticked = asyncio.Event()

        async def tick(self):
            ticked.set()

        async def poll(*args, **kwargs):
            await asyncio.wait_for(ticked.wait(), 1)

        def factory(app):
            dispatcher = original_factory(app)
            dispatcher.start_polling = AsyncMock(side_effect=poll)
            return dispatcher

        monkeypatch.setattr(main, "create_dispatcher", factory)
        monkeypatch.setattr(main.ReminderService, "tick", tick)
        await main.run(replace(config, bot_token="not-used-by-mocked-constructor"))
        assert ticked.is_set() and session.closed
        assert not any(t.get_name() == "reminders" for t in asyncio.all_tasks())
        assert [type(c).__name__ for c in session.calls] == ["DeleteWebhook", "SetMyCommands"]

    asyncio.run(scenario())
