import secrets

from aiogram import F, Router
from aiogram.filters import Command, CommandStart
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback
from uniflow.handlers.forms import start_add
from uniflow.handlers.planning import show_tasks, show_week
from uniflow.handlers.settings import show_settings
from uniflow.handlers.statistics import show_statistics
from uniflow.keyboards.main import (
    ADD,
    AI,
    EXPLAIN,
    HELP,
    MENU,
    SETTINGS,
    STATS,
    TASKS,
    TODAY,
    WEEK,
    inline,
    main_keyboard,
)

WELCOME = """Привет! Я <b>UniFlow</b> - твой помощник по учебным дедлайнам.

Я помогу:
• собрать задания в одном месте;
• определить, что важнее;
• составить план на сегодня;
• напомнить о дедлайнах;
• показать нагрузку на неделю.

Добавь первую задачу или попробуй демо."""
HELP_TEXT = """<b>UniFlow - что сделать сегодня</b>

➕ /add - добавить задачу пошагово
📚 /today - план на сегодня
📅 /week - нагрузка на 7 дней
📋 /tasks - задачи, редактирование, завершение и удаление
⚙️ /settings - часовой пояс и напоминания
✨ /ai - разобрать задание через ИИ или локально
✨ /explain - объяснить план
/help - помощь
📊 /stats - статистика за всё время
🎓 /demo - демонстрационные задания
/start - главное меню · /cancel - отмена ввода

Быстрый ввод: «Лаба по Java до пятницы 2 часа».
Можно прислать целое сообщение преподавателя: покажу задачи перед сохранением.
Без указанного времени дедлайн - в 23:59. Напоминания работают, пока бот запущен."""


async def navigate(action: str, message: Message, state: FSMContext, app: AppContext, user_id: int):
    app.cancel_job(user_id)
    await state.clear()
    if action == "add":
        await start_add(message, state, app)
        return
    if action == "start":
        await message.answer(WELCOME, reply_markup=main_keyboard())
        await message.answer(
            "С чего начнём?",
            reply_markup=inline(
                [
                    [("➕ Добавить первую задачу", "nav:add")],
                    [("🎓 Запустить демо", "nav:demo")],
                ]
            ),
        )
        await show_settings(message, state, app, user_id)
    elif action == "settings":
        await show_settings(message, state, app, user_id)
    elif action in {"ai", "explain"}:
        from uniflow.handlers.ai import start_ai

        await start_ai(action, message, state, app, user_id)
    elif action == "help":
        await message.answer(HELP_TEXT, reply_markup=main_keyboard())
    elif action == "demo":
        token = secrets.token_hex(4)
        await state.update_data(demo_token=token)
        await message.answer(
            "🎓 Добавить 4 демо-задачи? Предыдущие демо-задачи, включая выполненные, "
            "будут заменены. Твои обычные задачи сохранятся.",
            reply_markup=inline(
                [
                    [("🎓 Добавить демо", f"demo:{token}:yes")],
                    [("Отмена", f"demo:{token}:no")],
                ]
            ),
        )
    elif action == "today":
        await show_tasks(message, app, user_id, today=True)
    elif action == "tasks":
        await show_tasks(message, app, user_id)
    elif action == "week":
        await show_week(message, app, user_id)
    elif action == "stats":
        await show_statistics(message, app, user_id)


def navigation_router() -> Router:
    router = Router(name="navigation")

    @router.message(CommandStart())
    async def start(message: Message, state: FSMContext, app: AppContext):
        await navigate("start", message, state, app, message.from_user.id)

    @router.message(Command("cancel"))
    @router.message(F.text == "Отмена")
    async def cancel(message: Message, state: FSMContext, app: AppContext):
        app.cancel_job(message.from_user.id)
        await state.clear()
        await message.answer("Ввод отменён. Выбери действие.", reply_markup=main_keyboard())

    @router.message(
        Command(
            "today", "tasks", "week", "stats", "help", "demo", "add", "settings", "ai", "explain"
        )
    )
    @router.message(F.text.in_(MENU))
    async def command(message: Message, state: FSMContext, app: AppContext):
        actions = {
            TODAY: "today",
            TASKS: "tasks",
            WEEK: "week",
            STATS: "stats",
            HELP: "help",
            ADD: "add",
            SETTINGS: "settings",
            AI: "ai",
            EXPLAIN: "explain",
        }
        action = actions.get(message.text) or message.text.split()[0].split("@")[0][1:]
        
        if await state.get_state():
            await message.answer(
                "Перехожу к выбранному действию. Незавершённый ввод отменён.",
                reply_markup=main_keyboard(),
            )
        await navigate(action, message, state, app, message.from_user.id)

    @router.callback_query(F.data.startswith("nav:"))
    async def callback(callback: CallbackQuery, state: FSMContext, app: AppContext):
        await answer_callback(callback)
        action = callback.data.split(":")[-1]
        if isinstance(callback.message, Message):
            if await state.get_state():
                await callback.message.answer(
                    "Незавершённый ввод отменён.", reply_markup=main_keyboard()
                )
            await navigate(action, callback.message, state, app, callback.from_user.id)

    return router
