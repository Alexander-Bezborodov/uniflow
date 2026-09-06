from aiogram.types import Message

from uniflow.context import AppContext


async def show_statistics(message: Message, app: AppContext, user_id: int):
    stats = await app.tasks.statistics(user_id, await app.user_now(user_id))
    percent = stats.on_time_percent
    text = (
        "📊 <b>ТВОЯ СТАТИСТИКА</b>\n\nЗа всё время (включая демо):\n\n"
        f"✅ Выполнено: {stats.completed}\n⏰ Вовремя: {stats.on_time}\n"
        f"🚨 Выполнено с опозданием: {stats.completed_late}\n"
        f"📋 Сейчас активно: {stats.active}\n🚨 Из них просрочено: {stats.overdue_active}\n\n"
    )
    text += (
        f"🎯 Вовремя выполнено: {percent}% (из {stats.completed})"
        if percent is not None
        else "🎯 Процент появится после первой выполненной задачи."
    )
    await message.answer(text)
