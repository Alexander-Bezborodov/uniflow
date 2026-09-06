from html import escape

from aiogram.exceptions import TelegramBadRequest, TelegramForbiddenError
from aiogram.types import Message

from uniflow.context import AppContext
from uniflow.keyboards.main import inline
from uniflow.services.planning_service import PlanningService
from uniflow.utils.dates import DAY_SHORT, MONTHS, WEEKDAYS, date_label
from uniflow.utils.formatting import duration, task_card, text_units

PAGE_SIZE = 5


async def respond(message, text, *, reply_markup=None, edit=False):
    if edit:
        try:
            await message.edit_text(text, reply_markup=reply_markup)
            return
        except TelegramBadRequest as exc:
            if "message is not modified" in exc.message.lower():
                return
        except TelegramForbiddenError:
            pass
    await message.answer(text, reply_markup=reply_markup)


async def show_tasks(
    message: Message,
    app: AppContext,
    user_id: int,
    *,
    today: bool = False,
    page: int = 0,
    edit: bool = False,
    due_only: bool = False,
):
    now = await app.user_now(user_id)
    tasks = await app.database.list_tasks(user_id)
    plan = PlanningService.today(tasks, now)
    if due_only:
        plan = [
            item for item in plan if item.task.deadline.astimezone(now.tzinfo).date() == now.date()
        ]
    mode = "due" if due_only else "today" if today else "tasks"
    header = "📚 ПЛАН НА СЕГОДНЯ" if today else "📋 АКТИВНЫЕ ЗАДАЧИ"
    prefix = f"<b>{header}</b>\n{now.day} {MONTHS[now.month - 1]} · {escape(str(now.tzinfo))}\n"
    if today:
        prefix += "В плане есть подготовка к будущим дедлайнам.\n"
    blocks = []
    for index, item in enumerate(plan, 1):
        task = item.task
        if today:
            card = (
                ("🚨 <b>ПРОСРОЧЕНО</b>\n" if task.deadline.timestamp() <= now.timestamp() else "")
                + f"<b>{index}. {escape(task.title)}</b>"
                + (" 🎓 демо" if task.is_demo else "")
                + f"\n⏱ Поработать сегодня: {duration(item.minutes)}"
                + f"\n📅 Дедлайн: {date_label(task.deadline, now)}"
                + f"\nОценка всего: {duration(task.estimated_minutes)}"
                + f"\nПриоритет: {item.priority.label}\n"
            )
        else:
            card = f"<b>{index}.</b> " + task_card(task, now) + "\n"
        blocks.append(
            (
                card,
                [
                    (f"✅ {index}. Завершить задачу", f"task:done:{task.id}"),
                    (f"🔍 {index}. Подробнее", f"task:detail:{task.id}"),
                ],
            )
        )
    pages = [[]]
    for block in blocks:
        if pages[-1] and (
            len(pages[-1]) >= PAGE_SIZE
            or text_units("".join(b[0] for b in pages[-1]) + block[0]) > 2500
        ):
            pages.append([])
        pages[-1].append(block)
    page = max(0, min(page, len(pages) - 1))
    text = prefix + "\n" + "\n".join(b[0] for b in pages[page])
    rows = [b[1] for b in pages[page]]
    if not blocks:
        text += "Нет дедлайнов на сегодня." if due_only else "У тебя пока нет активных задач 🎉"
        rows.append([("➕ Добавить задачу", "nav:add"), ("🎓 Демо", "nav:demo")])
    if today:
        total = sum(item.minutes for item in plan)
        text += f"\n⏱ {'Выбранные задачи' if due_only else 'План целиком'}: {duration(total)}"
        if total > 360:
            text += "\nНагрузка выше 6 ч. Начни с первых задач и обсуди сроки с преподавателем."
        rows += [
            [("🔄 Обновить план", f"page:{mode}:{page}")],
            [
                (
                    "Весь план" if due_only else "Дедлайны сегодня",
                    "page:today:0" if due_only else "page:due:0",
                )
            ],
            [("✨ Объяснить план", "nav:explain")],
        ]
        text += "\nРекомендации - снимок на момент обновления."
    if len(pages) > 1:
        text += f"\n\nСтраница {page + 1}/{len(pages)}"
        navigation = []
        if page:
            navigation.append(("← Назад", f"page:{mode}:{page - 1}"))
        if page + 1 < len(pages):
            navigation.append(("Далее →", f"page:{mode}:{page + 1}"))
        rows.append(navigation)
    await respond(message, text, reply_markup=inline(rows), edit=edit)


async def show_week(message: Message, app: AppContext, user_id: int):
    now = await app.user_now(user_id)
    loads = PlanningService.week(await app.database.list_tasks(user_id), now)
    total = sum(day.minutes for day in loads)
    peak = max(loads, key=lambda day: day.minutes)
    scale = max(240, peak.minutes)
    text = f"📅 <b>НАГРУЗКА НА НЕДЕЛЮ</b>\n{escape(str(now.tzinfo))}\n\n<pre>"
    for day in loads:
        filled = min(8, max(1, round(day.minutes / scale * 8))) if day.minutes else 0
        bar = "█" * filled + "░" * (8 - filled)
        text += (
            f"{DAY_SHORT[day.day.weekday()]} {day.day:%d.%m} {bar} "
            f"{duration(day.minutes) if day.minutes else 'свободно'}\n"
        )
    text += "</pre>"
    if total:
        text += f"\n🔥 Самый загруженный день: {WEEKDAYS[peak.day.weekday()]}"
    text += f"\n⏱ Всего: {duration(total)}"
    text += (
        "\n\nПрогноз предполагает выполнение рекомендаций каждого дня. "
        "Учёт отработанных минут не ведётся."
    )
    if peak.minutes > 360:
        text += "\nЕсть дни с нагрузкой выше 6 ч - требуется пересмотр объёма или сроков."
    await message.answer(text)
