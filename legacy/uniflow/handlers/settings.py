import secrets
from html import escape
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, Message

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback
from uniflow.keyboards.main import inline, main_keyboard

ZONES = {
    "msk": ("Москва", "Europe/Moscow"),
    "ekb": ("Екатеринбург", "Asia/Yekaterinburg"),
    "nsk": ("Новосибирск", "Asia/Novosibirsk"),
    "vvo": ("Владивосток", "Asia/Vladivostok"),
    "utc": ("UTC", "UTC"),
}


class SettingsForm(StatesGroup):
    timezone = State()


async def show_settings(message: Message, state: FSMContext, app: AppContext, user_id: int):
    user = await app.database.get_user(user_id)
    now = await app.user_now(user_id)
    token = secrets.token_hex(4)
    await state.update_data(settings_token=token)
    rows = [
        [(label, f"settings:{token}:{key}") for key, (label, _) in list(ZONES.items())[i : i + 2]]
        for i in range(0, len(ZONES), 2)
    ]
    rows += [
        [("Другой IANA timezone", f"settings:{token}:custom")],
        [("Подтвердить текущий пояс", f"settings:{token}:confirm")],
        [
            (
                "Включить напоминания" if user["reminders_disabled"] else "Выключить напоминания",
                f"settings:{token}:reminders",
            )
        ],
        [("Отключить согласие на ИИ", f"settings:{token}:local")],
    ]
    await message.answer(
        f"⚙️ <b>НАСТРОЙКИ</b>\nПояс: {escape(user['timezone'])}\n"
        f"Местное время: {now:%d.%m.%Y %H:%M}\n"
        f"Напоминания: {'выключены' if user['reminders_disabled'] else 'включены'}.\n"
        "Проверь время и подтверди пояс. Сохранённые дедлайны сохранят абсолютный момент.",
        reply_markup=inline(rows),
    )


def settings_router():
    router = Router(name="settings")

    @router.callback_query(F.data.startswith("settings:"))
    async def callback(callback: CallbackQuery, state: FSMContext, app: AppContext):
        parts = callback.data.split(":")
        data = await state.get_data()
        if len(parts) != 3 or parts[1] != data.get("settings_token"):
            await answer_callback(callback, "Настройки устарели. Открой /settings.", alert=True)
            return
        await answer_callback(callback)
        if not isinstance(callback.message, Message):
            return
        action = parts[2]
        user_id = callback.from_user.id
        user = await app.database.get_user(user_id)
        app.cancel_job(user_id)
        if action == "custom":
            await state.set_state(SettingsForm.timezone)
            await callback.message.answer(
                "Введи IANA timezone, например Europe/Berlin. /cancel - отмена."
            )
            return
        if action in ZONES:
            await app.database.set_preferences(
                user_id, timezone=ZONES[action][1], timezone_confirmed=1
            )
        elif action == "confirm":
            await app.database.set_preferences(user_id, timezone_confirmed=1)
        elif action == "reminders":
            await app.database.set_preferences(
                user_id, reminders_disabled=1 - user["reminders_disabled"]
            )
        elif action == "local":
            await app.database.set_preferences(user_id, ai_consent=0)
        else:
            return
        await state.clear()
        await show_settings(callback.message, state, app, user_id)

    @router.message(SettingsForm.timezone)
    async def timezone(message: Message, state: FSMContext, app: AppContext):
        value = (message.text or "").strip()
        try:
            if len(value) > 100:
                raise ValueError
            ZoneInfo(value)
        except (ValueError, ZoneInfoNotFoundError):
            await message.answer("Неизвестный пояс. Пример: Europe/Berlin. Для отмены - /cancel.")
            return
        await app.database.set_preferences(
            message.from_user.id, timezone=value, timezone_confirmed=1
        )
        await state.clear()
        await message.answer("Часовой пояс сохранён.", reply_markup=main_keyboard())
        await show_settings(message, state, app, message.from_user.id)

    return router
