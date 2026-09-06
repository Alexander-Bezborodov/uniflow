import secrets
from html import escape

from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from uniflow.context import AppContext
from uniflow.handlers.common import answer_callback, clear_inline
from uniflow.handlers.planning import show_tasks
from uniflow.keyboards.main import inline
from uniflow.utils.formatting import task_card


def tasks_router() -> Router:
    router = Router(name="tasks")

    @router.callback_query(F.data.startswith("page:"))
    async def page(callback: CallbackQuery, app: AppContext, state: FSMContext):
        app.cancel_job(callback.from_user.id)
        await state.clear()
        parts = callback.data.split(":")
        await answer_callback(callback)
        if (
            len(parts) == 3
            and parts[1] in {"today", "tasks", "due"}
            and parts[2].isdigit()
            and len(parts[2]) <= 8
        ):
            if isinstance(callback.message, Message):
                await show_tasks(
                    callback.message,
                    app,
                    callback.from_user.id,
                    today=parts[1] in {"today", "due"},
                    due_only=parts[1] == "due",
                    edit=True,
                    page=int(parts[2]),
                )

    @router.callback_query(F.data.startswith("task:"))
    async def task_action(callback: CallbackQuery, state: FSMContext, app: AppContext):
        parts = callback.data.split(":")
        if len(parts) != 3 or not parts[2].isdigit() or len(parts[2]) > 18:
            await answer_callback(callback, "Кнопка устарела. Открой /tasks.", alert=True)
            return
        action, task_id = parts[1], int(parts[2])
        if action not in {"done", "detail", "delete", "edit"}:
            await answer_callback(callback, "Открой /tasks.")
            return
        app.cancel_job(callback.from_user.id)
        await state.clear()
        task = await app.database.get_task(callback.from_user.id, task_id)
        if task is None or task.status != "active":
            await answer_callback(callback, "Задача уже завершена или удалена.", alert=True)
            return
        if not isinstance(callback.message, Message):
            await answer_callback(callback, "Открой /tasks ещё раз.", alert=True)
            return
        await answer_callback(callback)
        if action == "done":
            changed = await app.database.complete_task(callback.from_user.id, task_id, app.now())
            if changed:
                await callback.message.answer(f"✅ Выполнено: <b>{escape(task.title)}</b>")
                await show_tasks(callback.message, app, callback.from_user.id, today=True)
        elif action == "detail":
            await callback.message.answer(
                task_card(task, app.now()),
                reply_markup=inline(
                    [
                        [
                            ("✅ Выполнено", f"task:done:{task.id}"),
                            ("🗑 Удалить", f"task:delete:{task.id}"),
                        ],
                        [
                            ("✏️ Редактировать", f"task:edit:{task.id}"),
                        ],
                    ]
                ),
            )
        elif action == "edit":
            from uniflow.handlers.forms import edit_saved_task

            await edit_saved_task(callback.message, state, app, task)
        elif action == "delete":
            token = secrets.token_hex(4)
            await state.update_data(delete_token=token, delete_task_id=task_id)
            await callback.message.answer(
                f"Точно удалить «{escape(task.title)}»?",
                reply_markup=inline(
                    [
                        [("Да, удалить", f"delete:{token}:yes"), ("Нет", f"delete:{token}:no")],
                    ]
                ),
            )

    @router.callback_query(F.data.startswith("delete:"))
    async def confirm_delete(callback: CallbackQuery, state: FSMContext, app: AppContext):
        parts = callback.data.split(":")
        data = await state.get_data()
        if len(parts) != 3 or parts[1] != data.get("delete_token"):
            await answer_callback(callback, "Подтверждение устарело. Открой /tasks.", alert=True)
            return
        if parts[2] not in {"yes", "no"}:
            await answer_callback(callback, "Открой /tasks ещё раз.", alert=True)
            return
        await answer_callback(callback)
        if parts[2] == "yes":
            changed = await app.database.delete_task(callback.from_user.id, data["delete_task_id"])
            text = "🗑 Задача удалена." if changed else "Задача уже завершена или удалена."
        else:
            text = "Удаление отменено."
        await state.update_data(delete_token=None, delete_task_id=None)
        await clear_inline(callback)
        if isinstance(callback.message, Message):
            await callback.message.answer(text)

    return router
