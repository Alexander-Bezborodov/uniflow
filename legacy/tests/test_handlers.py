import asyncio
from contextlib import asynccontextmanager
from datetime import UTC, datetime, timedelta

from aiogram import Bot
from aiogram.client.default import DefaultBotProperties
from aiogram.client.session.base import BaseSession
from aiogram.methods import EditMessageText, SendMessage
from aiogram.types import CallbackQuery, Chat, Message, Update, User

from uniflow.application import create_dispatcher
from uniflow.config import Config
from uniflow.context import AppContext
from uniflow.handlers.forms import Form, Review
from uniflow.models import NewTask


class FakeSession(BaseSession):
    

    def __init__(self):
        super().__init__()
        self.calls = []
        self.closed = False

    async def close(self):
        self.closed = True

    async def make_request(self, bot, method, timeout=None):  
        self.calls.append(method)
        if isinstance(method, SendMessage):
            return Message(
                message_id=len(self.calls),
                date=datetime.now(UTC),
                chat=Chat(id=int(method.chat_id), type="private"),
                from_user=User(id=bot.id, is_bot=True, first_name="UniFlow"),
                text=method.text,
                reply_markup=method.reply_markup
                if hasattr(method.reply_markup, "inline_keyboard")
                else None,
            )
        return True

    async def stream_content(self, *args, **kwargs):
        raise AssertionError("Downloads are forbidden in tests")
        yield b""  


class Harness:
    def __init__(self, app, bot, dispatcher, session):
        self.app, self.bot, self.dispatcher, self.session = app, bot, dispatcher, session
        self.sequence = 0

    def message(self, text, user_id=101, *, from_bot=False):
        return Message(
            message_id=self.sequence + 1,
            date=self.app.now(),
            chat=Chat(id=user_id, type="private"),
            text=text,
            from_user=User(
                id=self.bot.id if from_bot else user_id, is_bot=from_bot, first_name="Student"
            ),
        )

    async def send(self, text, user_id=101):
        self.sequence += 1
        await self.dispatcher.feed_update(
            self.bot, Update(update_id=self.sequence, message=self.message(text, user_id))
        )

    async def click(self, data, user_id=101):
        self.sequence += 1
        await self.dispatcher.feed_update(
            self.bot,
            Update(
                update_id=self.sequence,
                callback_query=CallbackQuery(
                    id=str(self.sequence),
                    chat_instance="offline",
                    from_user=User(id=user_id, is_bot=False, first_name="Student"),
                    data=data,
                    message=self.message("card", user_id, from_bot=True),
                ),
            ),
        )

    def button(self, prefix):
        for call in reversed(self.session.calls):
            markup = getattr(call, "reply_markup", None)
            if hasattr(markup, "inline_keyboard"):
                for row in markup.inline_keyboard:
                    for button in row:
                        if button.text.startswith(prefix):
                            return button.callback_data
        raise AssertionError(f"Button not found: {prefix}")

    def state(self, user_id=101):
        return self.dispatcher.fsm.get_context(bot=self.bot, chat_id=user_id, user_id=user_id)

    @property
    def text(self):
        return "\n".join(
            c.text for c in self.session.calls if isinstance(c, (SendMessage, EditMessageText))
        )


@asynccontextmanager
async def harness(tmp_path, now):
    app = AppContext.create(Config(database_path=tmp_path / "bot.db"))
    app.clock = lambda: now
    await app.database.initialize()
    session = FakeSession()
    
    bot = Bot(
        "999999:" + "offline" * 6, session=session, default=DefaultBotProperties(parse_mode="HTML")
    )
    dispatcher = create_dispatcher(app)
    try:
        yield Harness(app, bot, dispatcher, session)
    finally:
        await app.close_jobs()
        await dispatcher.storage.close()
        await dispatcher.fsm.events_isolation.close()
        await bot.session.close()


def test_start_manual_add_menu_complete_delete(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            assert "UniFlow" in h.text
            await h.click(h.button("➕ Добавить первую"))
            assert await h.state().get_state() == Form.title.state
            for text in (
                "Лаба <Java>",
                "Программирование",
                "ерунда",
                "завтра в 18:00",
                "0 минут",
                "2 часа",
                "🔴 Очень важная",
            ):
                await h.send(text)
            assert await h.state().get_state() is None
            (task,) = await h.app.database.list_tasks(101)
            assert task.title == "Лаба <Java>" and task.estimated_minutes == 120
            assert "&lt;Java&gt;" in h.text and "Не удалось распознать" in h.text
            for text in ("📚 Сегодня", "📅 Неделя", "📋 Все задачи", "📊 Статистика", "❓ Помощь"):
                await h.send(text)
            assert "ПЛАН НА СЕГОДНЯ" in h.text and "НАГРУЗКА НА НЕДЕЛЮ" in h.text
            await h.click(f"task:done:{task.id}")
            await h.click(f"task:done:{task.id}")
            assert not await h.app.database.list_tasks(101)
            await h.send("/stats")
            assert "Вовремя выполнено: 100%" in h.text
            await h.send("Лаба завтра 30 минут")
            await h.click(h.button("✅ Добавить все"))
            (second,) = await h.app.database.list_tasks(101)
            await h.click(f"task:delete:{second.id}")
            await h.click(h.button("Нет"))
            assert await h.app.database.get_task(101, second.id)
            await h.click(f"task:delete:{second.id}")
            confirm = h.button("Да, удалить")
            await h.click(confirm)
            await h.click(confirm)
            assert await h.app.database.get_task(101, second.id) is None
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())


def test_teacher_preview_fill_missing_edit_and_double_click(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send(
                "Коллеги, к следующему вторнику необходимо выполнить лабораторную работу №3, "
                "прочитать главы 4-5 и подготовиться к контрольной работе."
            )
            assert await h.state().get_state() == Review.ready.state
            assert "Найдено задач: 3" in h.text and "локальные правила" in h.text
            assert await h.app.database.list_tasks(101) == []
            stale = h.button("✅ Добавить все")
            await h.click(stale)
            for minutes in ("60 минут", "30 минут", "90 минут"):
                await h.send(minutes)
            await h.click(stale)
            assert await h.app.database.list_tasks(101) == []
            await h.click(h.button("✏️ Изменить"))
            await h.click(h.button("✏️ 1."))
            for value in (
                "Лабораторная №3 - исправлено",
                "Пропустить",
                "Оставить",
                "Оставить",
                "🟡 Важная",
            ):
                await h.send(value)
            save = h.button("✅ Добавить все")
            await asyncio.gather(h.click(save), h.click(save))
            tasks = await h.app.database.list_tasks(101)
            assert len(tasks) == 3
            assert any(t.title == "Лабораторная №3 - исправлено" for t in tasks)
            assert sum(t.estimated_minutes for t in tasks) == 180
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())


def test_demo_confirmation_and_stale_callbacks(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/demo")
            assert await h.app.database.list_tasks(101) == []
            confirm = h.button("🎓 Добавить демо")
            await asyncio.gather(h.click(confirm), h.click(confirm))
            first = await h.app.database.list_tasks(101)
            assert len(first) == 4
            await h.send("/demo")
            await h.click(h.button("🎓 Добавить демо"))
            assert len(await h.app.database.list_tasks(101)) == 4
            await h.click(f"task:done:{first[0].id}")
            await h.click("task:done:invalid")
            await h.click("unknown:old:button")
            assert "Что-то пошло не так" not in h.text

    asyncio.run(scenario())


def test_user_cannot_operate_another_users_tasks(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("Лаба завтра 60 минут")
            await h.click(h.button("✅ Добавить все"))
            (task,) = await h.app.database.list_tasks(101)
            for action in ("done", "detail", "delete"):
                await h.click(f"task:{action}:{task.id}", user_id=202)
            assert (await h.app.database.get_task(101, task.id)).status == "active"
            assert await h.app.database.list_tasks(202) == []

    asyncio.run(scenario())


def test_cancel_navigation_and_partial_quick_input(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/add")
            await h.send("/today")
            assert await h.state().get_state() is None
            await h.send("Лаба по Java")
            await h.click(h.button("✅ Добавить все"))
            assert await h.state().get_state() == Form.deadline.state
            await h.send("вчера")
            assert await h.state().get_state() == Form.deadline.state
            await h.send("через 3 дня")
            await h.send("90 минут")
            await h.click(h.button("✅ Добавить все"))
            assert len(await h.app.database.list_tasks(101)) == 1
            await h.send("/add")
            await h.send("Отмена")
            assert await h.state().get_state() is None
            await h.send("абракадабра")
            await h.send("/unknown")
            assert "Не нашёл учебных задач" in h.text and "Такой команды нет" in h.text

    asyncio.run(scenario())


def test_pagination_and_global_error_recovery(tmp_path, now):
    async def scenario():
        async with harness(tmp_path, now) as h:
            await h.send("/start")
            await h.app.database.create_tasks(
                101,
                [NewTask(title=f"Лаба {i}", deadline=now + timedelta(days=2)) for i in range(8)],
            )
            await h.send("/tasks")
            assert "Страница 1/2" in h.text
            await h.click(h.button("Далее"))
            assert "Страница 2/2" in h.text
            original = h.app.database.list_tasks

            async def broken(*args, **kwargs):
                raise RuntimeError("simulated error")

            h.app.database.list_tasks = broken
            await h.send("/today")
            assert "Что-то пошло не так" in h.text
            h.app.database.list_tasks = original
            await h.send("/today")
            assert "ПЛАН НА СЕГОДНЯ" in h.text

    asyncio.run(scenario())
