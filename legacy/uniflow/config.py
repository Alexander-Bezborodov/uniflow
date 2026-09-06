from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlparse
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from dotenv import dotenv_values

PROJECT_ROOT = Path(__file__).resolve().parent.parent


class ConfigError(ValueError):
    pass


@dataclass(frozen=True)
class Config:
    bot_token: str = field(default="", repr=False)
    timezone: str = "Asia/Yekaterinburg"
    database_path: Path = PROJECT_ROOT / "data/uniflow.db"
    llm_enabled: bool = False
    llm_api_key: str = field(default="", repr=False)
    llm_base_url: str = ""
    llm_model: str = ""

    @property
    def tz(self) -> ZoneInfo:
        return ZoneInfo(self.timezone)

    @property
    def llm_available(self) -> bool:
        url = urlparse(self.llm_base_url)
        return bool(
            self.llm_enabled
            and self.llm_api_key
            and self.llm_model
            and url.scheme in {"http", "https"}
            and url.hostname
            and not url.username
            and not url.password
            and not url.query
            and not url.fragment
        )

    @classmethod
    def load(cls, path: Path | None = None, *, require_token: bool = True) -> "Config":
        
        values = dotenv_values(path if path is not None else PROJECT_ROOT / ".env")

        def value(key: str, default: str = "") -> str:
            return (values.get(key) or default).strip()

        timezone = value("TIMEZONE", "Asia/Yekaterinburg")
        try:
            ZoneInfo(timezone)
        except (ZoneInfoNotFoundError, ValueError) as exc:
            raise ConfigError("TIMEZONE не распознан. Пример: Asia/Yekaterinburg.") from exc
        enabled = value("LLM_ENABLED", "false").lower()
        if enabled not in {"true", "false"}:
            raise ConfigError("LLM_ENABLED должен быть true или false.")
        db_path = Path(value("DATABASE_PATH", "data/uniflow.db"))
        if not db_path.is_absolute():
            db_path = PROJECT_ROOT / db_path
        config = cls(
            bot_token=value("BOT_TOKEN"),
            timezone=timezone,
            database_path=db_path,
            llm_enabled=enabled == "true",
            llm_api_key=value("LLM_API_KEY"),
            llm_base_url=value("LLM_BASE_URL").rstrip("/"),
            llm_model=value("LLM_MODEL"),
        )
        if require_token and not config.bot_token:
            raise ConfigError("Заполните BOT_TOKEN в .env. Шаблон находится в .env.example.")
        return config
