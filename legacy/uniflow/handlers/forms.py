import asyncio
import secrets
from html import escape

from aiogram import F, Router
from aiogram.filters import StateFilter
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, Message
from pydantic import ValidationError

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback, clear_inline
from uniflow.keyboards.main import IMPORTANCE, after_add, form_keyboard, inline, main_keyboard
from uniflow.models import NewTask, TaskDraft
from uniflow.utils.dates import date_label, parse_deadline, parse_duration
from uniflow.utils.formatting import duration, task_card


class Form(StatesGroup):
    title = State()
    subject = State()
    deadline = State()
    estimated_minutes = State()
    importance = State()


class Review(StatesGroup):
    ready = State()


class SavedEdit(StatesGroup):
    select = State()
    confirm = State()


FIELDS = ("title", "subject", "deadline", "estimated_minutes", "importance")
PROMPTS = {
    "title": "Как называется задача? До 200 символов.",
    "subject": "Какой предмет? Можно нажать «Пропустить».",
    "deadline": (
        "Когда дедлайн? Например: завтра, 10 сентября, 10.09.2026, в пятницу, "
        "через 3 дня. Можно добавить время: завтра в 18:00.\nБез времени - 23:59."
    ),
    "estimated_minutes": "Сколько потребуется времени? Например: 30 минут, 1 час, 1.5 часа.",
    "importance": "Насколько важна задача?",
}


async def ask_field(message: Message, state: FSMContext, app: AppContext, field: str):
    data = await state.get_data()
    current = data["drafts"][data["index"]]
    existing = current.get(field)
    keep = data.get("mode") in {"edit", "saved_edit"} and (
        existing is not None or field == "subject"
    )
    text = PROMPTS[field]
    if field == "deadline":
        text += f"\nЧасовой пояс: {escape(str((await app.user_now(state.key.user_id)).tzinfo))}."
    if keep:
        display = existing
        if field == "deadline":
            display = date_label(
                TaskDraft.model_validate(current).deadline, await app.user_now(state.key.user_id)
            )
        elif field == "estimated_minutes":
            display = duration(existing)
        elif field == "importance":
            display = next(name for name, value in IMPORTANCE.items() if value == existing)
        text += f"\nСейчас: {escape(str(display or 'не указан'))}. Можно нажать «Оставить»."
    if len(data["drafts"]) > 1:
        text = f"Задача {data['index'] + 1}/{len(data['drafts'])}: " + text
    await state.set_state(getattr(Form, field))
    await message.answer(text, reply_markup=form_keyboard(field, allow_keep=keep))


async def start_add(message: Message, state: FSMContext, app: AppContext):
    await state.clear()
    await state.update_data(drafts=[{}], index=0, mode="manual")
    await ask_field(message, state, app, "title")


async def show_preview(message: Message, state: FSMContext, app: AppContext):
    data = await state.get_data()
    drafts = [TaskDraft.model_validate(d) for d in data["drafts"]]
    token = secrets.token_hex(4)
    await state.update_data(review_token=token)
    await state.set_state(Review.ready)
    source = "ИИ" if data.get("source") == "llm" else "локальный разбор (локальные правила)"
    now = await app.user_now(state.key.user_id)
    await message.answer(
        f"🔎 Найдено задач: {len(drafts)}. Разбор: {source}.\n"
        + (
            "ИИ недоступен или ограничен; использован локальный вариант.\n"
            if data.get("llm_error")
            else ""
        )
        + "Проверь названия, даты и время перед добавлением.",
        reply_markup=main_keyboard(),
    )
    for start in range(0, len(drafts), 3):
        text = []
        for index, draft in enumerate(drafts[start : start + 3], start + 1):
            deadline = date_label(draft.deadline, now) if draft.deadline else "нужно уточнить"
            minutes = (
                duration(draft.estimated_minutes) if draft.estimated_minutes else "нужно уточнить"
            )
            text.append(
                f"<b>{index}. {escape(draft.title)}</b>\n"
                f"Предмет: {escape(draft.subject or 'не указан')}\n"
                f"📅 Дедлайн: {deadline}\n⏱ Оценка всего: {minutes}\n"
                f"Важность: {draft.importance}/3"
            )
        last = start + 3 >= len(drafts)
        keyboard = (
            inline(
                [
                    [("✅ Добавить все", f"review:{token}:add")],
                    [("✏️ Изменить", f"review:{token}:edit")],
                    [("❌ Отмена", f"review:{token}:cancel")],
                ]
            )
            if last
            else None
        )
        await message.answer("\n\n".join(text), reply_markup=keyboard)


async def fill_missing(message: Message, state: FSMContext, app: AppContext):
    data = await state.get_data()
    for index, draft in enumerate(data["drafts"]):
        for field in ("deadline", "estimated_minutes"):
            if draft.get(field) is None:
                await state.update_data(index=index, mode="missing")
                await ask_field(message, state, app, field)
                return
    await show_preview(message, state, app)


async def finish_form(message: Message, state: FSMContext, app: AppContext):
    data = await state.get_data()
    if data["mode"] == "manual":
        task = NewTask.model_validate(data["drafts"][0])
        now = await app.user_now(state.key.user_id)
        if task.deadline.timestamp() <= now.timestamp():
            await message.answer("Срок истёк во время ввода. Укажи новый дедлайн.")
            await ask_field(message, state, app, "deadline")
            return
        saved = await app.database.create_tasks(state.key.user_id, [task], now=now)
        
        await state.clear()
        await message.answer("✅ Задача добавлена", reply_markup=main_keyboard())
        await message.answer(
            task_card(saved[0], await app.user_now(state.key.user_id)), reply_markup=after_add()
        )
    else:
        await fill_missing(message, state, app)


async def form_answer(message: Message, state: FSMContext, app: AppContext):
    if not message.text:
        await message.answer("На этом шаге нужен текст. Для отмены - /cancel.")
        return
    field = (await state.get_state()).split(":")[-1]
    data = await state.get_data()
    current = data["drafts"][data["index"]]
    text = message.text.strip()
    keep = (
        text == "Оставить"
        and data["mode"] in {"edit", "saved_edit"}
        and (current.get(field) is not None or field == "subject")
    )
    if keep:
        value = current.get(field)
    elif field == "title":
        value = text
        if not 1 <= len(value) <= 200:
            await message.answer("Название должно содержать от 1 до 200 символов.")
            return
    elif field == "subject":
        value = None if text.lower() in {"пропустить", "/skip", "-"} else text
        if value is not None and not 1 <= len(value) <= 100:
            await message.answer("Предмет - от 1 до 100 символов, либо «Пропустить».")
            return
    elif field == "deadline":
        now = await app.user_now(state.key.user_id)
        value = await asyncio.to_thread(parse_deadline, text, now)
        if value is None:
            await message.answer("Не удалось распознать дату. Пример: 10.09.2026 в 18:00.")
            return
        if value.timestamp() <= now.timestamp():
            await message.answer("Эта дата уже прошла. Укажи будущий дедлайн.")
            return
        await message.answer(f"📅 Дедлайн: {date_label(value, now)} · {escape(str(now.tzinfo))}")
        value = value.isoformat()
    elif field == "estimated_minutes":
        value = parse_duration(text)
        if value is None:
            await message.answer("Укажи время от 1 до 10080 минут. Например: 1.5 часа.")
            return
    else:
        value = IMPORTANCE.get(text)
        if value is None:
            await message.answer("Выбери важность кнопкой: обычная, важная или очень важная.")
            return
    current[field] = value
    await state.update_data(drafts=data["drafts"])
    if data["mode"] == "saved_edit":
        if keep:
            await state.clear()
            await message.answer("Поле оставлено без изменений.", reply_markup=main_keyboard())
            return
        token = secrets.token_hex(4)
        await state.update_data(edit_token=token, edit_field=field)
        await state.set_state(SavedEdit.confirm)
        await message.answer(
            "Сохранить изменение?\n"
            + task_card(data_to_task(data, current), await app.user_now(state.key.user_id)),
            reply_markup=inline(
                [
                    [
                        ("Сохранить", f"editconfirm:{token}:save"),
                        ("Отмена", f"editconfirm:{token}:cancel"),
                    ]
                ]
            ),
        )
    elif data["mode"] == "missing":
        await fill_missing(message, state, app)
    elif field == "importance":
        await finish_form(message, state, app)
    else:
        await ask_field(message, state, app, FIELDS[FIELDS.index(field) + 1])


async def review_callback(callback: CallbackQuery, state: FSMContext, app: AppContext):
    data = await state.get_data()
    parts = callback.data.split(":")
    if (
        len(parts) not in (3, 4)
        or await state.get_state() != Review.ready.state
        or parts[1] != data.get("review_token")
        or not isinstance(callback.message, Message)
    ):
        await answer_callback(callback, "Предпросмотр устарел. Отправь текст ещё раз.", alert=True)
        return
    action = parts[2]
    await answer_callback(callback)
    if action == "cancel":
        await state.clear()
        await clear_inline(callback)
        await callback.message.answer("Добавление отменено.", reply_markup=main_keyboard())
    elif action == "edit" and len(parts) == 3:
        rows = [
            [(f"✏️ {i + 1}. {draft['title'][:40]}", f"review:{parts[1]}:edit:{i}")]
            for i, draft in enumerate(data["drafts"])
        ]
        await callback.message.answer("Какую задачу изменить?", reply_markup=inline(rows))
    elif action == "edit" and len(parts) == 4 and parts[3].isdigit():
        index = int(parts[3])
        if index >= len(data["drafts"]):
            return
        await state.update_data(mode="edit", index=index)
        await clear_inline(callback)
        await ask_field(callback.message, state, app, "title")
    elif action == "add":
        drafts = [TaskDraft.model_validate(d) for d in data["drafts"]]
        now = await app.user_now(state.key.user_id)
        
        changed = False
        for draft in drafts:
            if draft.deadline and draft.deadline <= now:
                draft.deadline = None
                changed = True
        if changed:
            await state.update_data(drafts=[d.model_dump(mode="json") for d in drafts])
            await callback.message.answer(
                "Некоторые сроки уже прошли. Уточним их перед сохранением."
            )
        if any(d.deadline is None or d.estimated_minutes is None for d in drafts):
            await clear_inline(callback)
            await fill_missing(callback.message, state, app)
            return
        try:
            tasks = [NewTask.model_validate(d.model_dump()) for d in drafts]
        except ValidationError:
            await callback.message.answer(
                "Не удалось проверить задачи. Отправь исходный текст ещё раз."
            )
            await state.clear()
            return
        await app.database.create_tasks(callback.from_user.id, tasks, now=now)
        await state.clear()
        await clear_inline(callback)
        await callback.message.answer(
            f"✅ Добавлено задач: {len(tasks)}", reply_markup=main_keyboard()
        )
        await callback.message.answer("План уже пересчитан.", reply_markup=after_add())


async def parse_message(message: Message, state: FSMContext, app: AppContext):
    if message.text.startswith("/"):
        await message.answer("Такой команды нет. Список действий - /help.")
        return
    if len(message.text) > 6000:
        await message.answer(
            "Текст слишком длинный. Отправь до 6000 символов или разбей его на части."
        )
        return
    result = await app.parser.parse(
        message.text, await app.user_now(state.key.user_id), use_llm=False
    )
    await accept_parse_result(message, state, app, result)


async def accept_parse_result(message: Message, state: FSMContext, app: AppContext, result):
    if not result.tasks:
        await message.answer(
            "Не нашёл учебных задач. Попробуй: «Лаба по Java до пятницы 2 часа» или нажми /add."
        )
        return
    await state.clear()
    await state.update_data(
        drafts=[d.model_dump(mode="json") for d in result.tasks],
        source=result.source,
        llm_error=result.error,
        index=0,
        mode="missing",
    )
    await show_preview(message, state, app)


def data_to_task(data, current):
    from dataclasses import replace

    from uniflow.models import Task

    original = data["saved_task"]
    return replace(Task(**original), **TaskDraft.model_validate(current).model_dump())


async def edit_saved_task(message, state, app, task):
    from dataclasses import asdict

    token = secrets.token_hex(4)
    await state.clear()
    await state.update_data(
        edit_token=token,
        saved_task=asdict(task),
        mode="saved_edit",
        index=0,
        drafts=[{key: getattr(task, key) for key in FIELDS}],
    )
    await state.set_state(SavedEdit.select)
    labels = ("Название", "Предмет", "Дедлайн", "Длительность", "Важность")
    await message.answer(
        "Что изменить? Старые данные:\n" + task_card(task, await app.user_now(state.key.user_id)),
        reply_markup=inline(
            [
                [(label, f"editfield:{token}:{field}")]
                for label, field in zip(labels, FIELDS, strict=True)
            ]
            + [[("Отмена", f"editconfirm:{token}:cancel")]]
        ),
    )


async def saved_edit_callback(callback, state, app):
    data = await state.get_data()
    parts = callback.data.split(":")
    if (
        len(parts) != 3
        or parts[1] != data.get("edit_token")
        or not isinstance(callback.message, Message)
    ):
        await answer_callback(callback, "Редактирование устарело. Открой /tasks.", alert=True)
        return
    await answer_callback(callback)
    if parts[0] == "editfield":
        if await state.get_state() != SavedEdit.select.state or parts[2] not in FIELDS:
            return
        await ask_field(callback.message, state, app, parts[2])
    elif parts[2] == "cancel":
        await state.clear()
        await callback.message.answer("Изменения отменены.", reply_markup=main_keyboard())
    elif parts[2] == "save" and await state.get_state() == SavedEdit.confirm.state:
        old = data["saved_task"]
        field = data["edit_field"]
        try:
            changed = await app.database.update_task(
                callback.from_user.id,
                old["id"],
                old["revision"],
                field,
                data["drafts"][0][field],
                await app.user_now(callback.from_user.id),
            )
        except ValueError:
            await callback.message.answer("Срок истёк. Укажи будущий дедлайн.")
            await ask_field(callback.message, state, app, "deadline")
            return
        await state.clear()
        await clear_inline(callback)
        await callback.message.answer(
            "✅ Изменение сохранено."
            if changed
            else "Задача уже изменена, завершена или удалена. Открой /tasks.",
            reply_markup=main_keyboard(),
        )


def form_router() -> Router:
    router = Router(name="forms")
    router.callback_query.register(saved_edit_callback, F.data.startswith("editfield:"))
    router.callback_query.register(saved_edit_callback, F.data.startswith("editconfirm:"))
    router.callback_query.register(review_callback, F.data.startswith("review:"))
    router.message.register(form_answer, StateFilter(Form))

    @router.message(StateFilter(SavedEdit))
    async def select_edit(message: Message):
        await message.answer("Выбери поле или подтверждение кнопкой. /cancel - отмена.")

    return router


def parser_router() -> Router:
    router = Router(name="text_parser")
    router.message.register(parse_message, F.text)

    @router.message()
    async def unsupported(message: Message):
        await message.answer(
            "Отправь задание текстом или выбери действие в меню.", reply_markup=main_keyboard()
        )

    @router.callback_query()
    async def stale(callback: CallbackQuery):
        await answer_callback(callback, "Кнопка устарела. Открой /tasks или /today.", alert=True)

    return router
