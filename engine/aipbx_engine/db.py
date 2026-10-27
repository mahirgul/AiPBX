"""MariaDB access for the discovery engine.

Credentials come from ``/etc/ai-pbx.env``, the file the portal reads, so the
engine runs with no secret of its own. It only ever touches two things:

* it **reads** ``sys_settings`` (the four ``provision_discovery_*`` keys an
  administrator edits on the Phones page) and ``pbx_phones`` (which MACs are
  already provisioned, so the log line can say what is new), and
* it **writes** ``pbx_phone_discoveries``.

It never writes ``pbx_phones``, ``pbx_phones_waiting`` or a phone file: that is
the portal's job, and keeping it that way is what makes "we never touch a phone
that is already provisioned" a property of the code rather than a promise.

The connection is opened lazily and replaced when it breaks, like the signaling
daemon's: a database restart must not end discovery for good. Every method is
fail-open — a database that cannot be reached yields the default (or writes
nothing) and is reported in the run summary.
"""

from __future__ import annotations

from typing import Iterable, Optional

from . import config

#: Insert order of the discovery row.
INSERT_SQL = (
    "INSERT INTO pbx_phone_discoveries "
    "(mac, ip, vendor, hostname, source, first_seen_at, last_seen_at, seen_count) "
    "VALUES (%s, %s, %s, %s, %s, NOW(), NOW(), 1) "
    "ON DUPLICATE KEY UPDATE "
    "ip = VALUES(ip), "
    "vendor = IF(vendor = '', VALUES(vendor), vendor), "
    "hostname = IF(VALUES(hostname) = '', hostname, VALUES(hostname)), "
    "source = VALUES(source), "
    "last_seen_at = NOW(), "
    "seen_count = seen_count + 1"
)


class Store:
    """The engine's database handle."""

    def __init__(self, config_dict: Optional[dict] = None):
        self.config = config.db_config() if config_dict is None else config_dict
        self._conn = None
        self.rows_written = 0
        self.last_error = ""

    # ------------------------------------------------------------ connection

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

    def _fail(self, exc: Exception, what: str) -> None:
        self.last_error = f"{what}: {exc}"[:200]
        self._close()

    # -------------------------------------------------------------- settings

    def setting(self, key: str, default: str = "") -> str:
        """One value from the portal's sys_settings table (default if absent)."""
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT setting_value FROM sys_settings "
                    "WHERE setting_key = %s LIMIT 1", (key,))
                row = cur.fetchone()
            if row and row[0] is not None:
                return str(row[0])
        except Exception as exc:  # noqa: BLE001 - reported, never raised
            self._fail(exc, f"reading setting {key}")
        return default

    def settings(self) -> dict:
        """Every setting the engine reads: the portal's, else the default."""
        return {key: self.setting(key, default) for key, default in config.SETTINGS.items()}

    @staticmethod
    def is_on(value: str) -> bool:
        return str(value).strip().lower() in ("1", "yes", "true", "on")

    # ------------------------------------------------------------ discoveries

    def upsert(self, devices: Iterable[dict]) -> int:
        """Records what the engine saw. Returns how many rows were written.

        ``status``, ``first_seen_at`` and ``phone_id`` are deliberately left
        alone: the portal owns the decision, so a phone an administrator has
        provisioned or ignored keeps that state however often the engine sees
        it again. A MAC that is already a phone still gets its row — the page
        then shows it as a provisioned phone that is on the network — but the
        row can never become a second phone.
        """
        rows = [
            (d["mac"], d.get("ip", ""), d.get("vendor", ""), d.get("hostname", ""), d.get("source", ""))
            for d in devices
            if d.get("mac")
        ]
        if not rows:
            return 0
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.executemany(INSERT_SQL, rows)
            conn.commit()
        except Exception as exc:  # noqa: BLE001 - reported, never raised
            self._fail(exc, "writing discoveries")
            return 0
        self.rows_written += len(rows)
        return len(rows)

    def discovered_macs(self) -> set:
        """MACs the engine has recorded before, for the "new" count in the log."""
        return self._mac_column("SELECT mac FROM pbx_phone_discoveries")

    def provisioned_macs(self) -> set:
        """MACs that are already a phone (the ones nothing here may touch)."""
        return self._mac_column("SELECT mac FROM pbx_phones")

    def _mac_column(self, sql: str) -> set:
        try:
            conn = self.connection()
            with conn.cursor() as cur:
                cur.execute(sql)
                return {str(row[0]) for row in cur.fetchall()}
        except Exception as exc:  # noqa: BLE001 - reported, never raised
            self._fail(exc, "reading MAC addresses")
            return set()
