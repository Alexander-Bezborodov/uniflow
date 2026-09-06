import logging
from contextlib import suppress

from aiogram import BaseMiddleware, Bot
from aiogram.exceptions import TelegramAPIError
from aiogram.types import CallbackQuery, ErrorEvent, Message

from uniflow.context import USER_TIMEZONE, AppContext
from uniflow.keyboards.main import main_keyboard

logger = logging.getLogger(__name__)


class UserMiddleware(BaseMiddleware):
    async def __call__(self, handler, event, data):
        user = event.from_user
        message = event.message if isinstance(event, CallbackQuery) else event
        if user is None or message is None:
            return None
        if message.chat.type != "private" or message.chat.id != user.id:
            if isinstance(event, Message):
                await event.answer("UniFlow работает в личном чате. Открой бота и нажми /start.")
            elif isinstance(event, CallbackQuery):
                await event.answer("Открой личный чат с UniFlow.", show_alert=True)
            return None
        app: AppContext = data["app"]
        await app.database.register_user(
            user.id, user.username, user.full_name, app.config.timezone
        )
        settings = await app.database.get_user(user.id)
        token = USER_TIMEZONE.set(settings["timezone"])
        try:
            return await handler(event, data)
        finally:
            USER_TIMEZONE.reset(token)


async def answer_callback(callback: CallbackQuery, text: str | None = None, *, alert: bool = False):
    
    with suppress(TelegramAPIError):
        await callback.answer(text, show_alert=alert)


async def clear_inline(callback: CallbackQuery):
    if isinstance(callback.message, Message):
        with suppress(TelegramAPIError):
            await callback.message.edit_reply_markup(reply_markup=None)


async def handle_error(event: ErrorEvent, bot: Bot):
    logger.error("Ошибка обработки команды (%s)", type(event.exception).__name__)
    message = event.update.message
    callback = event.update.callback_query
    if callback:
        await answer_callback(
            callback, "Не удалось выполнить действие. Попробуй ещё раз.", alert=True
        )
        user_id = callback.from_user.id
    else:
        user_id = message.from_user.id if message and message.from_user else None
    if user_id:
        with suppress(TelegramAPIError):
            await bot.send_message(
                user_id,
                "Что-то пошло не так. Попробуй ещё раз или нажми /cancel.",
                reply_markup=main_keyboard(),
            )
    return True
