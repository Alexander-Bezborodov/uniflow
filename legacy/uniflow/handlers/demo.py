from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback, clear_inline
from uniflow.keyboards.main import inline, main_keyboard


def demo_router() -> Router:
    router = Router(name="demo")

    @router.callback_query(F.data.startswith("demo:"))
    async def demo(callback: CallbackQuery, state: FSMContext, app: AppContext):
        parts = callback.data.split(":")
        data = await state.get_data()
        if len(parts) != 3 or parts[1] != data.get("demo_token"):
            await answer_callback(callback, "Подтверждение устарело. Нажми /demo.", alert=True)
            return
        if not isinstance(callback.message, Message):
            await answer_callback(callback, "Нажми /demo ещё раз.", alert=True)
            return
        await answer_callback(callback)
        if parts[2] == "yes":
            await app.tasks.demo(callback.from_user.id, await app.user_now(callback.from_user.id))
            await state.clear()
            await clear_inline(callback)
            await callback.message.answer(
                "🎓 Демо-данные готовы!\n\nТеперь попробуй: 📚 Сегодня, 📅 Неделя, 📊 Статистика.",
                reply_markup=main_keyboard(),
            )
            await callback.message.answer(
                "Начнём с самого важного.",
                reply_markup=inline(
                    [
                        [("📚 Показать план", "nav:today")],
                    ]
                ),
            )
        elif parts[2] == "no":
            await state.clear()
            await clear_inline(callback)
            await callback.message.answer("Демо отменено.", reply_markup=main_keyboard())

    return router
