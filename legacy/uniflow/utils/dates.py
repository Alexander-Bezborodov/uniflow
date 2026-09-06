import math
import re
from datetime import UTC, datetime, timedelta
from zoneinfo import ZoneInfo

from uniflow.models import require_aware

MONTHS = (
    "января",
    "февраля",
    "марта",
    "апреля",
    "мая",
    "июня",
    "июля",
    "августа",
    "сентября",
    "октября",
    "ноября",
    "декабря",
)
WEEKDAYS = ("понедельник", "вторник", "среда", "четверг", "пятница", "суббота", "воскресенье")
DAY_SHORT = ("Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс")
DAY_PATTERN = r"понедельник\w*|вторник\w*|сред[ауы]|четверг\w*|пятниц\w*|суббот\w*|воскресень\w*"
MONTH_PATTERN = "|".join(MONTHS)
DATE_PATTERN = re.compile(
    rf"(?<![\w.])(?:через\s+\d+\s+(?:дня|дней|день|неделю|недели|недель)"
    rf"|послезавтра|завтра|сегодня|вчера"
    rf"|(?:(?:следующ\w*)\s+)?(?:{DAY_PATTERN})"
    rf"|\d{{4}}-\d{{2}}-\d{{2}}"
    rf"|\d{{1,2}}\.\d{{1,2}}(?:\.\d{{4}})?"
    rf"|\d{{1,2}}\s+(?:{MONTH_PATTERN})(?:\s+\d{{4}})?)"
    r"(?:\s*(?:в|к)?\s*\d{1,2}:\d{2})?\b(?!\.\d|\d)",
    re.IGNORECASE,
)
DURATION_PATTERN = re.compile(
    r"(?<![\w.,-])(?:\d+(?:[.,]\d+)?\s*(?:час(?:а|ов)?|ч\.?|минут(?:а|ы|у)?|мин\.?)"
    r"|полтора\s+часа|полчаса|час)(?!\w)",
    re.IGNORECASE,
)


def utc_string(value: datetime) -> str:
    return require_aware(value).astimezone(UTC).isoformat(timespec="microseconds")


def parse_deadline(text: str, now: datetime) -> datetime | None:
    
    require_aware(now)
    value = re.sub(r"^(?:до|к|в)\s+", "", text.strip().lower()).strip()
    if not DATE_PATTERN.fullmatch(value):
        return None
    clock = re.search(r"\s*(?:в|к)?\s*(\d{1,2}):(\d{2})$", value)
    hour, minute = (int(clock[1]), int(clock[2])) if clock else (23, 59)
    if hour > 23 or minute > 59:
        return None
    date_text = value[: clock.start()].strip() if clock else value
    try:
        if date_text in {"сегодня", "завтра", "послезавтра", "вчера"}:
            shift = {"сегодня": 0, "завтра": 1, "послезавтра": 2, "вчера": -1}[date_text]
            result = now + timedelta(days=shift)
        elif match := re.fullmatch(r"через\s+(\d+)\s+(\w+)", date_text):
            days = int(match[1]) * (7 if match[2].startswith("недел") else 1)
            if days > 3660:
                return None
            result = now + timedelta(days=days)
        elif re.fullmatch(rf"(?:следующ\w*\s+)?(?:{DAY_PATTERN})", date_text):
            stems = ("понедельник", "вторник", "сред", "четверг", "пятниц", "суббот", "воскресень")
            day = next(i for i, stem in enumerate(stems) if stem in date_text)
            delta = (day - now.weekday()) % 7
            if delta == 0 and "следующ" in date_text:
                delta = 7
            result = now + timedelta(days=delta)
        else:
            explicit_year = bool(re.search(r"\b\d{4}\b", date_text))
            if match := re.fullmatch(r"(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?", date_text):
                day, month, year = int(match[1]), int(match[2]), int(match[3] or now.year)
            elif re.fullmatch(r"\d{4}-\d{2}-\d{2}", date_text):
                year, month, day = map(int, date_text.split("-"))
            else:
                match = re.fullmatch(
                    rf"(\d{{1,2}})\s+({MONTH_PATTERN})(?:\s+(\d{{4}}))?", date_text
                )
                if not match:
                    return None
                day, month, year = (
                    int(match[1]),
                    MONTHS.index(match[2]) + 1,
                    int(match[3] or now.year),
                )
            if explicit_year:
                result = datetime(year, month, day, tzinfo=now.tzinfo)
            else:
                
                result = None
                for candidate_year in range(now.year, now.year + 9):
                    try:
                        candidate = datetime(candidate_year, month, day, tzinfo=now.tzinfo)
                    except ValueError:
                        continue
                    if candidate.date() >= now.date():
                        result = candidate
                        break
                if result is None:
                    return None
        result = result.replace(hour=hour, minute=minute, second=0, microsecond=0, fold=0)
        
        roundtrip = result.astimezone(UTC).astimezone(now.tzinfo)
        return result if roundtrip.replace(fold=0) == result.replace(fold=0) else None
    except (ValueError, OverflowError, StopIteration):
        return None


def extract_deadline(text: str, now: datetime) -> tuple[datetime | None, str]:
    match = DATE_PATTERN.search(text)
    if not match:
        return None, text
    return parse_deadline(match[0], now), text[: match.start()] + " " + text[match.end() :]


def parse_duration(text: str) -> int | None:
    value = text.strip().lower().replace(",", ".")
    value = re.sub(r"^(?:примерно|около|подготовка)\s+", "", value)
    if value.isdecimal():
        minutes = int(value)
    else:
        parts = list(DURATION_PATTERN.finditer(value))
        rest = DURATION_PATTERN.sub("", value).strip(" ,+и")
        if not parts or rest:
            return None
        minutes = 0.0
        for match in parts:
            part = match[0]
            if part == "полчаса":
                minutes += 30
            elif part == "полтора часа":
                minutes += 90
            elif part == "час":
                minutes += 60
            else:
                number = re.match(r"\d+(?:\.\d+)?", part)
                minutes += float(number[0]) * (60 if "ч" in part else 1)
    return math.ceil(minutes) if 0 < minutes <= 10080 else None


def local_now(timezone: str | ZoneInfo) -> datetime:
    return datetime.now(ZoneInfo(timezone) if isinstance(timezone, str) else timezone)


def date_label(value: datetime, now: datetime) -> str:
    local = value.astimezone(now.tzinfo)
    delta = (local.date() - now.date()).days
    relative = {0: "сегодня", 1: "завтра", 2: "послезавтра"}.get(delta)
    date = f"{local.day} {MONTHS[local.month - 1]}"
    if local.year != now.year:
        date += f" {local.year}"
    return f"{relative + ', ' if relative else ''}{date}, {local:%H:%M}"
