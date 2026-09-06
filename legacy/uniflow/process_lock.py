import hashlib
import os
import tempfile
from contextlib import contextmanager
from pathlib import Path

from uniflow.config import ConfigError


@contextmanager
def polling_lock(token: str):
    
    digest = hashlib.sha256(token.encode()).hexdigest()
    path = Path(tempfile.gettempdir()) / f"uniflow-{digest}.lock"
    with path.open("a+b") as handle:
        if os.name == "nt":
            import msvcrt

            handle.write(b"0")
            handle.flush()
            handle.seek(0)
            try:
                msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                raise ConfigError("Этот бот уже запущен на этом компьютере.") from None
            try:
                yield
            finally:
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl

            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                raise ConfigError("Этот бот уже запущен на этом компьютере.") from None
            try:
                yield
            finally:
                fcntl.flock(handle, fcntl.LOCK_UN)
