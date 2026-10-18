"""MariaDB access for the signaling capture daemon.

Credentials come from /etc/ai-pbx.env — the same file the portal reads, which
is `chown root:asterisk; chmod 640`, so the daemon runs with the `asterisk`
group exactly like Asterisk itself. The running application user
(DB_USER=aipbx_portal) has SELECT/INSERT/UPDATE/DELETE only; DDL belongs to
MIGRATOR_DB_USER, which is deliberately not used here.

The connection is opened lazily and replaced when it breaks: an Asterisk
restart, a database restart or a too-long idle period must never stop capture
for good.
"""

from __future__ import annotations

import os
import time
from typing import Iterable, Optional

ENV_FILE = "/etc/ai-pbx.env"

#: Rows are inserted in batches this large at most.
BATCH_MAX = 500

#: Days of events kept when the portal has no setting (see the portal's
#: Signaling page: "keep events for N days").
DEFAULT_RETENTION_DAYS = 30

INSERT_SQL = (
    "INSERT INTO pbx_signaling_events "
    "(ts, call_id, method, proto, transport, src, dst, from_uri, to_uri, "
    " cseq, status_code, first_line, line, capture_id) "
    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
)


def read_env(path: str = ENV_FILE) -> dict:
    """Parses the portal's environment file (key=value, # comments)."""
    env: dict = {}
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                env[key.strip()] = value.strip().strip("'\"")
    except OSError:
        return {}
    return env


def db_config(env: Optional[dict] = None) -> dict:
    """Connection settings, with the same names the portal uses."""
    env = read_env() if env is None else env
    return {
        "host": env.get("DB_HOST", "localhost"),
        "user": env.get("DB_USER", ""),
        "password": env.get("DB_PASS", ""),
        "database": env.get("DB_NAME", "asterisk"),
        "charset": "utf8mb4",
    }


class Store:
    """A MariaDB writer that survives a database that goes away and comes back."""

    def __init__(self, config: Optional[dict] = None):
        self.config = db_config() if config is None else config
        self._conn = None
        self.rows_written = 0
        self.write_errors = 0
        self.last_error = ""

    def _connect(self):
        import pymysql  # imported here so the module loads without the runtime

        return pymysql.connect(
            host=self.config["host"],
            user=self.config["user"],
            password=self.config["password"],
            database=self.config["database"],
            charset=self.config.get("charset", "utf8mb4"),
            autocommit=False,
            connect_timeout=5,
        )

    def connection(self):
        if self._conn is None:
            self._conn = self._connect()
        else:
            try:
                self._conn.ping(reconnect=True)
            except Exception:
                self._close()
                self._conn = self._connect()
        return self._conn

    def _close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.close()
            except Exception:
                pass
            self._conn = None

    @staticmethod
    def row(event: dict, fallback_ts: int) -> tuple:
        """One event -> the insert tuple, in INSERT_SQL order."""
        ts = int(event.get("ts") or 0) or fallback_ts
        return (
            time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(ts)),
            event.get("call_id", ""),
            event.get("method", ""),
            event.get("proto", ""),
            event.get("transport", ""),
            event.get("src", ""),
            event.get("dst", ""),
            event.get("from_uri", ""),
            event.get("to_uri", ""),
            event.get("cseq", ""),
            event.get("status_code", ""),
            event.get("first_line", ""),
            event.get("line", ""),
            event.get("capture_id", ""),
        )

    def write(self, events: Iterable[dict], now: Optional[int] = None) -> int:
        """Inserts events. Returns how many rows were written (0 on failure).

        Fail-open by design: capture never affects the PBX, so a database that
        is down loses events and is reported, it does not raise into the
        receive loop.
        """
        rows = [self.row(e, int(now if now is not None else time.time()))
                for e in events]
        if not rows:
            return 0
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.executemany(INSERT_SQL, rows)
            conn.commit()
        except Exception as exc:  # noqa: BLE001 - reported, never raised
            self.write_errors += 1
            self.last_error = str(exc)[:200]
            self._close()
            return 0
        self.rows_written += len(rows)
        return len(rows)

    def setting(self, key: str, default: str = "") -> str:
        """One value from the portal's sys_settings table.

        The daemon is configured through the same settings the portal writes,
        so there is one source of truth and no second config file to drift.
        A database that cannot be reached yields the default (fail-open).
        """
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT setting_value FROM sys_settings "
                    "WHERE setting_key = %s LIMIT 1", (key,))
                row = cur.fetchone()
            if row and row[0] is not None:
                return str(row[0])
        except Exception:
            self._close()
        return default

    def retention_days(self) -> int:
        """The portal's setting, or the default when it cannot be read."""
        value = self.setting("signaling_retention_days", "")
        if value.isdigit():
            # 1..3650: a 0 or a silly value would delete everything or
            # nothing, so the setting is clamped here too.
            return min(max(int(value), 1), 3650)
        return DEFAULT_RETENTION_DAYS

    def prune(self, days: Optional[int] = None) -> int:
        """Drops events older than the retention window. Returns rows deleted."""
        days = self.retention_days() if days is None else days
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.execute(
                    "DELETE FROM pbx_signaling_events "
                    "WHERE ts < (NOW() - INTERVAL %s DAY)", (days,))
                deleted = cur.rowcount
            conn.commit()
            return max(deleted, 0)
        except Exception as exc:  # noqa: BLE001
            self.last_error = str(exc)[:200]
            self._close()
            return 0
