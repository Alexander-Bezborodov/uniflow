import logging
import re


class SecretFormatter(logging.Formatter):
    def __init__(self, secrets: tuple[str, ...]):
        super().__init__("%(asctime)s %(levelname)s %(name)s: %(message)s")
        self.secrets = tuple(secret for secret in secrets if secret)

    def format(self, record: logging.LogRecord) -> str:
        text = super().format(record)
        for secret in self.secrets:
            text = text.replace(secret, "[СКРЫТО]")
        return re.sub(r"\b\d{5,}:[A-Za-z0-9_-]{20,}\b", "[СКРЫТО]", text)


def configure_logging(*secrets: str):
    handler = logging.StreamHandler()
    handler.setFormatter(SecretFormatter(secrets))
    logging.basicConfig(level=logging.INFO, handlers=[handler], force=True)
    logging.getLogger("aiohttp").setLevel(logging.WARNING)
