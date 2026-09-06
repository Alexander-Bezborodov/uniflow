import asyncio
import logging
import secrets
from html import escape

from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, Message

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback, clear_inline
from uniflow.handlers.forms import accept_parse_result
from uniflow.keyboards.main import inline, main_keyboard
from uniflow.services.llm_service import LLMError
from uniflow.services.planning_service import PlanningService
from uniflow.utils.dates import date_label
from uniflow.utils.formatting import duration

logger = logging.getLogger(__name__)


class AIInput(StatesGroup):
    text = State()
    processing = State()


REASONS = {
    "deadline": "Срок близко: начни с этой задачи.",
    "importance": "У задачи высокая важность.",
    "volume": "Объём большой, поэтому полезно начать заранее.",
    "prepare": "Подготовка сегодня уменьшит нагрузку перед сдачей.",
}


def plan_facts(plan, now):
    facts = []
    for index, item in enumerate(plan[:10], 1):
        hours = (item.task.deadline.timestamp() - now.timestamp()) / 3600
        reasons = ["deadline"] if hours <= 72 else ["prepare"]
        if item.task.importance == 3:
            reasons.append("importance")
        if item.task.estimated_minutes >= 180:
            reasons.append("volume")
        facts.append(
            {
                "task_ref": index,
                "title": item.task.title,
                "deadline": item.task.deadline.astimezone(now.tzinfo).isoformat(),
                "estimated_minutes": item.task.estimated_minutes,
                "minutes": item.minutes,
                "priority": item.priority.name,
                "allowed_reasons": reasons,
            }
        )
    return facts


def plan_version(plan, now):
    return (
        now.date(),
        str(now.tzinfo),
        tuple((i.task.id, i.task.revision, i.minutes, i.priority) for i in plan),
    )


def render_explanation(plan, facts, now, reasons=None, *, ai=False, failed=False):
    if not plan:
        return (
            "Активных задач нет. Добавь задание - помогу выбрать, с чего начать. Локальный шаблон."
        )
    text = (
        "✨ <b>С ЧЕГО НАЧАТЬ</b>\nИсточник: "
        + ("ИИ (причины проверены)" if ai else "локальный шаблон")
        + ".\n"
    )
    if failed:
        text += "ИИ недоступен или ответ не прошёл проверку.\n"
    for index, (item, fact) in enumerate(zip(plan[:3], facts[:3], strict=True)):
        reason = reasons[index] if reasons else fact["allowed_reasons"][0]
        text += (
            f"\n<b>{index + 1}. {escape(item.task.title)}</b>\n{REASONS[reason]}\n"
            f"Рекомендация на сегодня: {duration(item.minutes)}.\n"
            f"Дедлайн: {date_label(item.task.deadline, now)}.\n"
        )
    text += (
        f"\nПлан целиком: {duration(sum(i.minutes for i in plan))}. "
        f"Объяснены первые {min(3, len(plan))} задачи."
    )
    return text


async def start_ai(action, message, state, app, user_id):
    if action == "explain" and not await app.database.list_tasks(user_id):
        await message.answer(render_explanation([], [], await app.user_now(user_id)))
        return
    token = secrets.token_hex(4)
    await state.update_data(ai_token=token, ai_action=action)
    text = (
        "ИИ обрабатывает текст задания или названия и факты плана у внешнего провайдера "
        "(Groq при настройках из шаблона). Telegram ID и профиль не передаются. "
        "Выбери способ обработки; согласие можно отключить в /settings."
    )
    rows = []
    if app.parser.llm is not None:
        rows.append([("✨ Использовать ИИ", f"ai:{token}:provider")])
    else:
        text += "\nИИ выключен или не настроен. Доступен локальный вариант."
    rows.append([("Локальный вариант", f"ai:{token}:local")])
    await message.answer(text, reply_markup=inline(rows))


async def launch_job(message, state, app, user_id, work, finish):
    token = secrets.token_hex(8)
    await state.set_state(AIInput.processing)
    await state.update_data(job_token=token)
    await message.answer("⏳ Обрабатываю… /cancel - отменить.", reply_markup=main_keyboard())

    async def worker():
        try:
            result = await work()
            
            async with app.isolation.lock(key=state.key):
                if (await state.get_data()).get("job_token") != token:
                    return
                await finish(result)
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            logger.error("AI-сценарий: %s", type(exc).__name__)
            async with app.isolation.lock(key=state.key):
                if (await state.get_data()).get("job_token") == token:
                    await state.clear()
                    try:
                        await message.answer(
                            "Не удалось закончить обработку. Попробуй /ai или /cancel."
                        )
                    except Exception:
                        logger.warning("Не удалось отправить статус AI-сценария")
        finally:
            if app.jobs.get(user_id) is asyncio.current_task():
                app.jobs.pop(user_id, None)

    app.jobs[user_id] = asyncio.create_task(worker(), name="ai-request")


async def explain_plan(message, state, app, user_id, use_llm):
    now = await app.user_now(user_id)
    plan = PlanningService.today(await app.database.list_tasks(user_id), now)
    facts = plan_facts(plan, now)
    version = plan_version(plan, now)
    if not use_llm or not plan or app.parser.llm is None:
        await state.clear()
        await message.answer(render_explanation(plan, facts, now))
        return

    async def work():
        try:
            return await app.parser.llm.explain(facts, now, user_id)
        except Exception as exc:
            logger.warning(
                "Объяснение fallback: %s",
                exc.category if isinstance(exc, LLMError) else type(exc).__name__,
            )
            return None

    async def finish(result):
        current = await app.user_now(user_id)
        latest = PlanningService.today(await app.database.list_tasks(user_id), current)
        await state.clear()
        if plan_version(latest, current) != version:
            await message.answer(
                "План изменился во время обработки. Использую актуальный локальный расчёт.\n"
                + render_explanation(latest, plan_facts(latest, current), current)
            )
            return
        await message.answer(
            render_explanation(
                plan,
                facts,
                current,
                [step.reason for step in result.steps] if result else None,
                ai=result is not None,
                failed=result is None,
            )
        )

    await launch_job(message, state, app, user_id, work, finish)


def ai_router():
    router = Router(name="ai")

    @router.callback_query(F.data.startswith("ai:"))
    async def choice(callback: CallbackQuery, state: FSMContext, app: AppContext):
        data = await state.get_data()
        parts = callback.data.split(":")
        if (
            len(parts) != 3
            or parts[1] != data.get("ai_token")
            or parts[2] not in {"provider", "local"}
            or not isinstance(callback.message, Message)
        ):
            await answer_callback(callback, "Выбор устарел. Открой /ai или /explain.", alert=True)
            return
        await answer_callback(callback)
        await state.update_data(ai_token=None)
        await clear_inline(callback)
        use_llm = parts[2] == "provider" and app.parser.llm is not None
        if use_llm:
            await app.database.set_preferences(callback.from_user.id, ai_consent=1)
        if data["ai_action"] == "explain":
            await explain_plan(callback.message, state, app, callback.from_user.id, use_llm)
        else:
            await state.set_state(AIInput.text)
            await state.update_data(use_llm=use_llm)
            await callback.message.answer(
                "Отправь текст задания (до 6000 символов, до 10 задач). "
                + ("Обработка: ИИ." if use_llm else "Обработка: локальный разбор.")
                + " Сохранение только после проверки и подтверждения. /cancel - отмена.",
                reply_markup=main_keyboard(),
            )

    @router.message(AIInput.processing)
    async def busy(message: Message):
        await message.answer("Обработка ещё идёт. /cancel - отменить; меню переключит раздел.")

    @router.message(AIInput.text)
    async def text(message: Message, state: FSMContext, app: AppContext):
        if not message.text or not message.text.strip() or len(message.text) > 6000:
            await message.answer("Нужен текст от 1 до 6000 символов. /cancel - отмена.")
            return
        data = await state.get_data()
        user_id = message.from_user.id
        now = await app.user_now(user_id)
        use_llm = data.get("use_llm", False)
        if not use_llm:
            result = await app.parser.parse(message.text, now, use_llm=False)
            await accept_parse_result(message, state, app, result)
            return

        async def work():
            return await app.parser.parse(message.text, now, user_id=user_id)

        async def finish(result):
            await accept_parse_result(message, state, app, result)

        await launch_job(message, state, app, user_id, work, finish)

    return router
