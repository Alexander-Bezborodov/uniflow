import asyncio
from datetime import UTC, datetime, timedelta

from aiogram.methods import EditMessageText, SendMessage
from test_handlers import harness

from uniflow.handlers.ai import AIInput
from uniflow.handlers.forms import Review
from uniflow.models import NewTask, TaskDraft
from uniflow.services.llm_service import ExplainedPlan, LLMError
from uniflow.utils.formatting import text_units


class ControlledAI:
    def __init__(self):
        self.started = asyncio.Event()
        self.release = asyncio.Event()
        self.calls = []
        self.fail = False
        self.ignore_cancel = False

    async def parse(self, text, now, user_id=None):
        self.calls.append((text, now, user_id))
        self.started.set()
        try:
            await self.release.wait()
        except asyncio.CancelledError:
            if not self.ignore_cancel:
                raise
            await self.release.wait()
        if self.fail:
            raise LLMError("http_429")
        return [
            TaskDraft(title="Лаба <Java>", deadline=now + timedelta(days=1), estimated_minutes=60)
        ]

    async def explain(self, facts, now, user_id):
        self.calls.append((facts, now, user_id))
        self.started.set()
        await self.release.wait()
        if self.fail:
            raise LLMError("inconsistent_explanation")
        return ExplainedPlan(
            steps=[
                {key: f[key] for key in ("task_ref", "minutes", "deadline")}
                | {"reason": f["allowed_reasons"][0]}
                for f in facts[:3]
            ]
        )


async def start_request(h, llm, text="Лаба завтра"):
    h.app.parser.llm = llm
    await h.send("/ai")
    choose = h.button("✨ Использовать ИИ")
    await asyncio.gather(h.click(choose), h.click(choose))
    await h.send(text)
    await asyncio.wait_for(llm.started.wait(), 1)
    return h.app.jobs[101]


def test_cancel_late_response_does_not_reopen_preview(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            llm = ControlledAI()
            llm.ignore_cancel = True
            job = await start_request(h, llm)
            await asyncio.wait_for(h.send("/cancel"), 1)
            llm.release.set()
            await job
            assert await h.state().get_state() is None
            assert not await h.app.database.list_tasks(101)
            assert "Найдено задач" not in h.text
            assert len(llm.calls) == 1

    asyncio.run(scenario())


def test_navigation_late_answer_and_independent_user(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            llm = ControlledAI()
            llm.ignore_cancel = True
            job = await start_request(h, llm)
            await asyncio.wait_for(h.send("/today", 202), 1)
            assert await h.state().get_state() == AIInput.processing.state
            await h.send("/add")
            llm.release.set()
            await job
            assert await h.state().get_state() == "Form:title"
            assert "Найдено задач" not in h.text

    asyncio.run(scenario())


def test_ai_preview_unknown_minutes_confirm_is_atomic_and_no_extra_calls(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            llm = ControlledAI()
            job = await start_request(h, llm)
            llm.release.set()
            await job
            assert await h.state().get_state() == Review.ready.state
            assert "Разбор: ИИ" in h.text and "нужно уточнить" in h.text
            assert not await h.app.database.list_tasks(101)
            await h.click(h.button("✅ Добавить все"))
            await h.send("30 минут")
            confirm = h.button("✅ Добавить все")
            await asyncio.gather(h.click(confirm), h.click(confirm))
            assert len(await h.app.database.list_tasks(101)) == 1
            assert len(llm.calls) == 1
            assert llm.calls[0][1].tzinfo == now.tzinfo

    asyncio.run(scenario())


def test_ai_error_source_and_local_option_do_not_call_provider(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            llm = ControlledAI()
            llm.fail = True
            job = await start_request(h, llm, "Лаба завтра 90 минут")
            llm.release.set()
            await job
            assert "ИИ недоступен" in h.text and "локальный разбор" in h.text
            await h.send("/ai")
            await h.click(h.button("Локальный вариант"))
            await h.send("Лаба завтра 30 минут")
            assert len(llm.calls) == 1
            await h.send("/cancel")
            await h.send("Лаба завтра 30 минут")
            assert len(llm.calls) == 1  

    asyncio.run(scenario())


def test_explanation_owner_facts_and_changes_during_request(tmp_path):
    async def scenario():
        now = datetime(2026, 9, 5, 19, 30, tzinfo=UTC)
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.click(h.button("Москва"))
            await h.app.database.register_user(202, None, None, "UTC")
            await h.app.database.create_tasks(
                202, [NewTask(title="Чужой секрет", deadline=now + timedelta(days=1))]
            )
            task = (
                await h.app.database.create_tasks(
                    101, [NewTask(title="Своя <задача>", deadline=now + timedelta(days=1))]
                )
            )[0]
            llm = ControlledAI()
            h.app.parser.llm = llm
            await h.send("/explain")
            consent = h.button("✨ Использовать ИИ")
            await asyncio.gather(h.click(consent), h.click(consent))
            await asyncio.wait_for(llm.started.wait(), 1)
            job = h.app.jobs[101]
            facts, local, user_id = llm.calls[0]
            assert "Чужой" not in str(facts) and "user_id" not in str(facts)
            assert str(local.tzinfo) == "Europe/Moscow" and local.day == 5
            assert len(llm.calls) == 1 and user_id == 101  
            await h.app.database.update_task(101, task.id, 0, "title", "Исправлена", now)
            llm.release.set()
            await job
            assert "План изменился" in h.text and "Исправлена" in h.text
            assert (await h.app.database.get_task(101, task.id)).revision == 1

    asyncio.run(scenario())


def test_valid_and_failed_explanations_and_empty_plan(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            llm = ControlledAI()
            h.app.parser.llm = llm
            await h.send("/explain")
            assert not llm.calls and "Локальный шаблон" in h.text
            await h.app.database.create_tasks(
                101, [NewTask(title="Лаба", deadline=now + timedelta(days=1))]
            )
            for failure in (False, True):
                llm.fail = failure
                llm.release.set()
                await h.send("/today")
                await h.click(h.button("✨ Объяснить план"))
                await h.click(h.button("✨ Использовать ИИ"))
                job = h.app.jobs.get(101)
                if job:
                    await job
                assert ("локальный шаблон" if failure else "ИИ (причины проверены)") in h.text
            assert (await h.app.database.list_tasks(101))[0].revision == 0

    asyncio.run(scenario())


def test_long_unicode_html_cards_pagination_and_unsupported(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.app.database.create_tasks(
                101,
                [
                    NewTask(
                        title="🧑\u200d💻<&" * 33,
                        subject="😀<&" * 33,
                        deadline=now + timedelta(days=1),
                        estimated_minutes=10080,
                    )
                    for _ in range(12)
                ],
            )
            for command in ("/today", "/tasks"):
                await h.send(command)
                await h.click(h.button("Далее"))
            await h.send("/add")
            await h.send(None)
            await h.send("/cancel")
            await h.send(None)
            for call in h.session.calls:
                if isinstance(call, (SendMessage, EditMessageText)):
                    assert text_units(call.text) <= 4096
            assert "&lt;&amp;" in h.text and "Что-то пошло не так" not in h.text

    asyncio.run(scenario())
