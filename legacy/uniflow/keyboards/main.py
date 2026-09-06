from aiogram.types import (
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    ReplyKeyboardMarkup,
)

TODAY = "📚 Сегодня"
ADD = "➕ Добавить задачу"
TASKS = "📋 Все задачи"
WEEK = "📅 Неделя"
STATS = "📊 Статистика"
HELP = "❓ Помощь"
SETTINGS = "⚙️ Настройки"
AI = "✨ Разобрать задание"
EXPLAIN = "✨ Объяснить план"
MENU = {TODAY, ADD, TASKS, WEEK, STATS, HELP, SETTINGS, AI, EXPLAIN}
IMPORTANCE = {"🟢 Обычная": 1, "🟡 Важная": 2, "🔴 Очень важная": 3}


def main_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text=t) for t in row]
            for row in ((TODAY, ADD), (TASKS, WEEK), (AI, EXPLAIN), (SETTINGS, STATS), (HELP,))
        ],
        resize_keyboard=True,
        input_field_placeholder="Отправь задание или выбери действие",
    )


def inline(rows: list[list[tuple[str, str]]]) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text=text, callback_data=data) for text, data in row]
            for row in rows
        ]
    )


def after_add() -> InlineKeyboardMarkup:
    return inline([[("📚 План на сегодня", "nav:today"), ("➕ Добавить ещё", "nav:add")]])


def form_keyboard(field: str, *, allow_keep: bool = False) -> ReplyKeyboardMarkup:
    rows = []
    if field == "subject":
        rows.append(["Пропустить"])
    if field == "importance":
        rows.extend([[name] for name in IMPORTANCE])
    if allow_keep:
        rows.append(["Оставить"])
    rows.append(["Отмена"])
    return ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=t) for t in row] for row in rows], resize_keyboard=True
    )
