import asyncio
import logging
import re
from dataclasses import dataclass
from datetime import datetime
from difflib import SequenceMatcher

from uniflow.models import TaskDraft
from uniflow.services.llm_service import LLMError, LLMService
from uniflow.utils.dates import DATE_PATTERN, DURATION_PATTERN, extract_deadline, parse_duration

logger = logging.getLogger(__name__)
ACTION = (
    r"(?:выполнить|сделать|решить|сдать|написать|прочитать|ознакомиться|подготовиться|подготовить)"
)
STUDY = re.compile(
    rf"\b(?:{ACTION}|лаб\w*|дз|домашн\w*|контрольн\w*|экзамен\w*|зач[её]т\w*"
    r"|реферат\w*|эссе|курсов\w*|проект\w*|презентаци\w*|задан\w*|подготовка)\b",
    re.I,
)
SPLIT = re.compile(rf"(?:[,;\n]+|\s+и\s+)(?=\s*{ACTION}\b)", re.I)


@dataclass(frozen=True)
class ParseResult:
    tasks: list[TaskDraft]
    source: str  
    error: str | None = None


def heuristic_parse(text: str, now: datetime) -> list[TaskDraft]:
    text = text.strip()
    if not text or len(text) > 6000:
        return []
    shared_deadline = None
    
    first_action = re.search(rf"\b{ACTION}\b", text, re.I)
    if first_action and (
        re.match(r"^(?:коллеги|студенты|ребята|добрый день|здравствуйте)\b", text, re.I)
        or re.search(r"\b(?:необходимо|нужно|прошу)\b", text[: first_action.start()], re.I)
    ):
        shared_deadline, _ = extract_deadline(text[: first_action.start()], now)
        text = text[first_action.start() :]
    parts = SPLIT.split(text)
    
    parts = [p for chunk in parts for p in re.split(r"[;\n]+", chunk) if p.strip()]
    if len(parts) > 10:
        return []
    tasks = []
    for part in parts:
        part = part.strip()
        if not STUDY.search(part) and not DATE_PATTERN.search(part):
            continue
        deadline, title = extract_deadline(part, now)
        duration_matches = list(DURATION_PATTERN.finditer(title))
        minutes = None
        if duration_matches:
            durations = " ".join(m[0] for m in duration_matches)
            minutes = parse_duration(durations)
            if re.search(r"-\s*\d", title):
                minutes = None
            title = DURATION_PATTERN.sub("", title)
        title = re.sub(r"\b(?:примерно|около|подготовка)\s*[,.:]*\s*$", "", title, flags=re.I)
        title = re.sub(r"\b(?:до|к|в)\s*[,.:]*\s*$", "", title, flags=re.I)
        title = re.sub(r"\b(?:до|к)\s*(?=[,;]|$)", "", title, flags=re.I)
        title = re.sub(r"^ознакомиться\s+с\s+главами", "Прочитать главы", title, flags=re.I)
        title = re.sub(
            r"^выполнить\s+лабораторную\s+работу", "Лабораторная работа", title, flags=re.I
        )
        title = re.sub(r"\s+", " ", title).strip(" ,.;:---•")
        if not title or len(title) > 200:
            continue
        title = title[0].upper() + title[1:]
        tasks.append(
            TaskDraft(
                title=title,
                deadline=deadline
                if (DATE_PATTERN.search(part) or re.search(r"\d{1,4}[./-]\d{1,2}", part))
                else shared_deadline,
                estimated_minutes=minutes,
            )
        )
    return tasks


class ParserService:
    def __init__(self, llm: LLMService | None = None):
        self.llm = llm

    async def parse(
        self, text: str, now: datetime, *, use_llm: bool = True, user_id: int | None = None
    ) -> ParseResult:
        if not text.strip() or len(text) > 6000:
            return ParseResult([], "heuristic")
        error = None
        if use_llm and self.llm is not None:
            try:
                tasks = (
                    await self.llm.parse(text, now, user_id)
                    if user_id is not None
                    else await self.llm.parse(text, now)
                )
                local = await asyncio.to_thread(heuristic_parse, text, now)

                
                
                def normalized(title):
                    return " ".join(re.findall(r"\w+", title.casefold()))

                scores = [
                    [
                        SequenceMatcher(
                            None, normalized(task.title), normalized(reference.title)
                        ).ratio()
                        for reference in local
                    ]
                    for task in tasks
                ]
                for index, task in enumerate(tasks):
                    task.deadline = None
                    task.estimated_minutes = None
                    if len(local) != len(tasks) or not local:
                        continue
                    best = max(scores[index])
                    if best < 0.5 or scores[index].count(best) != 1:
                        continue
                    target = scores[index].index(best)
                    
                    
                    column = [row[target] for row in scores]
                    if column[index] != max(column) or column.count(max(column)) != 1:
                        continue
                    task.deadline = local[target].deadline
                    task.estimated_minutes = local[target].estimated_minutes
                if tasks:
                    return ParseResult(tasks, "llm")
            except Exception as exc:
                
                error = exc.category if isinstance(exc, LLMError) else type(exc).__name__
                logger.warning("LLM fallback: %s", error)
        tasks = await asyncio.to_thread(heuristic_parse, text, now)
        return ParseResult(tasks, "heuristic", error)
