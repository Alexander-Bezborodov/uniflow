

import argparse
import sqlite3
from pathlib import Path

from uniflow.config import Config


def backup(source: Path, destination: Path):
    if not source.is_file():
        raise ValueError("Исходная база не существует.")
    
    with destination.open("xb"):
        pass
    try:
        with sqlite3.connect(source.resolve().as_uri() + "?mode=ro", uri=True) as src:
            with sqlite3.connect(destination) as dst:
                src.backup(dst)
                if dst.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
                    raise ValueError("Проверка целостности резервной копии не пройдена.")
    except BaseException:
        destination.unlink(missing_ok=True)
        raise


def cli():
    parser = argparse.ArgumentParser(description="Резервная копия БД UniFlow до миграции")
    parser.add_argument(
        "destination", type=Path, help="Новый файл, например backup-before-update.db"
    )
    args = parser.parse_args()
    try:
        backup(Config.load(require_token=False).database_path, args.destination)
    except (OSError, ValueError, sqlite3.Error) as exc:
        print(f"Резервная копия не создана ({type(exc).__name__}). Проверь пути и права.")
        return 1
    print("Резервная копия создана; SQLite integrity_check: ok.")
    return 0


if __name__ == "__main__":
    raise SystemExit(cli())
