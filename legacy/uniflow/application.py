from aiogram import Dispatcher
from aiogram.fsm.storage.memory import MemoryStorage, SimpleEventIsolation

from uniflow.context import AppContext
from uniflow.handlers.ai import ai_router
from uniflow.handlers.common import UserMiddleware, handle_error
from uniflow.handlers.demo import demo_router
from uniflow.handlers.forms import form_router, parser_router
from uniflow.handlers.settings import settings_router
from uniflow.handlers.start import navigation_router
from uniflow.handlers.tasks import tasks_router


def create_dispatcher(app: AppContext) -> Dispatcher:
    dispatcher = Dispatcher(
        storage=MemoryStorage(), events_isolation=SimpleEventIsolation(), app=app
    )
    app.isolation = dispatcher.fsm.events_isolation
    dispatcher.message.outer_middleware(UserMiddleware())
    dispatcher.callback_query.outer_middleware(UserMiddleware())
    dispatcher.errors.register(handle_error)
    
    dispatcher.include_routers(
        navigation_router(),
        settings_router(),
        ai_router(),
        demo_router(),
        tasks_router(),
        form_router(),
        parser_router(),
    )
    return dispatcher
