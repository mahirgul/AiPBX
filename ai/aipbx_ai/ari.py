"""Asterisk REST Interface (ARI) External Media orchestrator for aipbx_ai.

Stdlib-only REST + RFC 6455 WebSocket client so live AI calls enter via
Stasis(aipbx-ai, <app_id>, <call_uuid>) and bridge into the existing
AudioSocket server (127.0.0.1:8791) via externalMedia(encapsulation=audiosocket).
"""
from __future__ import annotations

import base64
import json
import logging
import os
import socket
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

from .ws_reader import WebSocketReader

log = logging.getLogger("aipbx_ai.ari")

# How long a new external media channel may take to enter Stasis before the caller goes back to the dialplan.
JOIN_TIMEOUT = 3.0


class AriError(RuntimeError):
    """Raised when an ARI REST call fails."""


class AriClient:
    """Minimal stdlib HTTP client for Asterisk ARI (/ari/*)."""

    def __init__(self, base_url: str, username: str, password: str, timeout: float = 5.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.username = username
        self.password = password
        self.timeout = timeout
        raw = f"{username}:{password}".encode("utf-8")
        self._auth = "Basic " + base64.b64encode(raw).decode("ascii")

    def request(
        self,
        method: str,
        path: str,
        params: dict[str, Any] | None = None,
        body: dict[str, Any] | None = None,
    ) -> Any:
        url = f"{self.base_url}/{path.lstrip('/')}"
        if params:
            clean = {k: str(v) for k, v in params.items() if v is not None and v != ""}
            if clean:
                url += "?" + urllib.parse.urlencode(clean)
        data = None
        headers = {"Authorization": self._auth, "Accept": "application/json"}
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, method=method.upper(), headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read()
                if not raw:
                    return None
                return json.loads(raw.decode("utf-8"))
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace")[:300]
            raise AriError(f"ARI {method} {path} -> {e.code}: {detail}") from e
        except OSError as e:
            raise AriError(f"ARI {method} {path} failed: {e}") from e

    def list_channels(self) -> list[dict[str, Any]]:
        res = self.request("GET", "/channels")
        return res if isinstance(res, list) else []

    def answer(self, channel_id: str) -> None:
        self.request("POST", f"/channels/{urllib.parse.quote(channel_id, safe='')}/answer")

    def continue_in_dialplan(
        self, channel_id: str, context: str = "", extension: str = "", priority: int = 1
    ) -> None:
        params: dict[str, Any] = {}
        if context:
            params["context"] = context
        if extension:
            params["extension"] = extension
        if context or extension:
            params["priority"] = priority
        self.request("POST", f"/channels/{urllib.parse.quote(channel_id, safe='')}/continue", params=params)

    def hangup(self, channel_id: str, reason: str = "normal") -> None:
        self.request("DELETE", f"/channels/{urllib.parse.quote(channel_id, safe='')}", params={"reason": reason})

    def create_bridge(self, bridge_type: str = "mixing") -> dict[str, Any]:
        res = self.request("POST", "/bridges", params={"type": bridge_type})
        return res if isinstance(res, dict) else {}

    def add_channel(self, bridge_id: str, channel_id: str) -> None:
        self.request(
            "POST",
            f"/bridges/{urllib.parse.quote(bridge_id, safe='')}/addChannel",
            params={"channel": channel_id},
        )

    def remove_channel(self, bridge_id: str, channel_id: str) -> None:
        self.request(
            "POST",
            f"/bridges/{urllib.parse.quote(bridge_id, safe='')}/removeChannel",
            params={"channel": channel_id},
        )

    def destroy_bridge(self, bridge_id: str) -> None:
        self.request("DELETE", f"/bridges/{urllib.parse.quote(bridge_id, safe='')}")

    def external_media(
        self,
        app: str,
        external_host: str,
        fmt: str = "slin",
        encapsulation: str = "audiosocket",
        transport: str = "tcp",
        channel_id: str = "",
        data: str = "",
    ) -> dict[str, Any]:
        params: dict[str, Any] = {
            "app": app,
            "external_host": external_host,
            "format": fmt,
            "encapsulation": encapsulation,
            "transport": transport,
        }
        if channel_id:
            params["channelId"] = channel_id
        if data:
            params["data"] = data
        res = self.request("POST", "/channels/externalMedia", params=params)
        return res if isinstance(res, dict) else {}

    def originate(
        self,
        endpoint: str,
        app: str,
        app_args: str = "",
        caller_id: str = "",
        timeout: int = 30,
    ) -> dict[str, Any]:
        params: dict[str, Any] = {
            "endpoint": endpoint,
            "app": app,
            "timeout": timeout,
        }
        if app_args:
            params["appArgs"] = app_args
        if caller_id:
            params["callerId"] = caller_id
        res = self.request("POST", "/channels", params=params)
        return res if isinstance(res, dict) else {}

    def play(self, channel_id: str, media: str) -> dict[str, Any]:
        res = self.request(
            "POST",
            f"/channels/{urllib.parse.quote(channel_id, safe='')}/play",
            params={"media": media},
        )
        return res if isinstance(res, dict) else {}

    def send_dtmf(self, channel_id: str, dtmf: str, between: int = 100, duration: int = 100) -> None:
        clean = "".join(ch for ch in str(dtmf or "") if ch in "0123456789*#ABCDwW")
        if not clean:
            raise AriError("empty DTMF sequence")
        between = max(50, min(500, int(between)))
        duration = max(50, min(500, int(duration)))
        buf: list[str] = []
        for ch in clean:
            if ch in ("w", "W"):
                if buf:
                    self.request(
                        "POST",
                        f"/channels/{urllib.parse.quote(channel_id, safe='')}/dtmf",
                        params={"dtmf": "".join(buf), "between": between, "duration": duration},
                    )
                    buf.clear()
                time.sleep(0.5)
            else:
                buf.append(ch)
        if buf:
            self.request(
                "POST",
                f"/channels/{urllib.parse.quote(channel_id, safe='')}/dtmf",
                params={"dtmf": "".join(buf), "between": between, "duration": duration},
            )


@dataclass
class AriCallState:
    call_uuid: str
    caller_channel_id: str
    bridge_id: str
    ext_channel_id: str
    app_id: str = ""
    transferred: bool = False


class AriSessionManager:
    """Handles ARI Stasis events and manages bridges + AudioSocket ExternalMedia."""

    def __init__(
        self,
        client: AriClient,
        app_name: str = "aipbx-ai",
        audiosocket_host: str = "127.0.0.1",
        audiosocket_port: int = 8791,
    ) -> None:
        self.client = client
        self.app_name = app_name
        self.audiosocket_host = audiosocket_host
        self.audiosocket_port = audiosocket_port
        self._lock = threading.Lock()
        self._by_caller: dict[str, AriCallState] = {}
        self._by_uuid: dict[str, AriCallState] = {}
        self._ext_channels: set[str] = set()
        self._pending_xfers: dict[str, dict[str, Any]] = {}
        # external media channels created but not yet in Stasis: ext id -> (bridge, call uuid, caller, timer)
        self._pending_join: dict[str, tuple[str, str, str, Any]] = {}
        self.on_dtmf: Any = None
        self.on_error: Any = None      # (call_uuid, text): shown in the call log
        self.outbound_navigator: Any = None

    def register_ext_channel(self, channel_id: str) -> None:
        with self._lock:
            self._ext_channels.add(channel_id)

    def unregister_ext_channel(self, channel_id: str) -> None:
        with self._lock:
            self._ext_channels.discard(channel_id)

    def get_caller_channel_id(self, call_uuid: str) -> str:
        with self._lock:
            state = self._by_uuid.get(call_uuid)
            return state.caller_channel_id if state is not None else ""

    def send_dtmf_for_call(self, call_uuid_or_channel: str, dtmf: str, between: int = 100, duration: int = 100) -> bool:
        with self._lock:
            state = self._by_uuid.get(call_uuid_or_channel)
            target = state.caller_channel_id if state is not None else call_uuid_or_channel
        if not target:
            return False
        self.client.send_dtmf(target, dtmf, between=between, duration=duration)
        return True

    def rebridge_caller(self, call_uuid: str, target_bridge_id: str) -> bool:
        """Moves an active AI caller into an existing bridge and detaches the AI leg."""
        with self._lock:
            state = self._by_uuid.pop(call_uuid, None)
            if state is not None:
                self._by_caller.pop(state.caller_channel_id, None)
                self._ext_channels.discard(state.ext_channel_id)
        if state is None:
            return False
        if state.transferred:
            return False
        for fn in (
            lambda: self.client.remove_channel(state.bridge_id, state.caller_channel_id),
            lambda: self.client.add_channel(target_bridge_id, state.caller_channel_id),
            lambda: self.client.remove_channel(state.bridge_id, state.ext_channel_id),
            lambda: self.client.hangup(state.ext_channel_id),
            lambda: self.client.destroy_bridge(state.bridge_id),
        ):
            try:
                fn()
            except AriError:
                pass
        return True

    def handle_event(self, event: dict[str, Any]) -> None:
        etype = str(event.get("type") or "")
        if etype == "StasisStart":
            self._on_stasis_start(event)
        elif etype == "StasisEnd":
            self._on_stasis_end(event)
        elif etype == "ChannelDestroyed":
            self._on_channel_destroyed(event)
        elif etype == "ChannelDtmfReceived":
            self._on_channel_dtmf(event)
        elif etype == "ChannelStateChange" and self.outbound_navigator is not None:
            self.outbound_navigator.on_ari_event(event)

    def _on_channel_dtmf(self, event: dict[str, Any]) -> None:
        ch = event.get("channel") or {}
        ch_id = str(ch.get("id") or "")
        digit = str(event.get("digit") or "")
        if not ch_id or not digit:
            return
        if self.outbound_navigator is not None:
            self.outbound_navigator.on_ari_event(event)
        with self._lock:
            state = self._by_caller.get(ch_id)
        if state is not None and self.on_dtmf is not None:
            try:
                self.on_dtmf(state.call_uuid, digit)
            except Exception:
                pass

    def _on_stasis_start(self, event: dict[str, Any]) -> None:
        ch = event.get("channel") or {}
        ch_id = str(ch.get("id") or "")
        if not ch_id:
            return
        args = [str(a) for a in (event.get("args") or [])]

        with self._lock:
            is_ext = ch_id in self._ext_channels
            pending = self._pending_join.pop(ch_id, None) if is_ext else None
        if is_ext:
            if pending is not None:          # our media channel has entered Stasis: now it can join its bridge
                self._join_pending(ch_id, pending)
            return

        if args and args[0] == "xfer" and len(args) >= 2:
            self._on_xfer_stasis_start(ch_id, args[1])
            return

        if args and args[0] in ("outbound_nav", "outbound_bridge"):
            if self.outbound_navigator is not None:
                self.outbound_navigator.on_ari_event(event)
            return

        if args and args[0] == "ext":
            return

        app_id = args[0] if len(args) >= 1 else ""
        call_uuid = args[1] if len(args) >= 2 and args[1] else ch_id
        try:
            self.attach_caller(ch_id, call_uuid, app_id=app_id)
        except AriError:
            log.exception("Could not attach ARI caller %s", ch_id)
            if self.on_error is not None:
                try:
                    self.on_error(call_uuid, "ARI could not join the call to the media channel")
                except Exception:  # noqa: BLE001 - a log entry must never keep the caller from the dialplan
                    pass
            try:
                self.client.continue_in_dialplan(ch_id)
            except AriError:
                log.exception("Could not resume dialplan for ARI caller %s", ch_id)

    def attach_caller(self, caller_channel_id: str, call_uuid: str, app_id: str = "") -> AriCallState:
        """Answers caller, creates mixing bridge + AudioSocket externalMedia, joins both."""
        self.client.answer(caller_channel_id)
        bridge = self.client.create_bridge("mixing")
        bridge_id = str(bridge.get("id") or "")
        if not bridge_id:
            raise AriError("ARI did not return a bridge id")
        state = None
        try:
            self.client.add_channel(bridge_id, caller_channel_id)

            ext = self.client.external_media(
                app=self.app_name,
                external_host=f"{self.audiosocket_host}:{self.audiosocket_port}",
                fmt="slin",
                encapsulation="audiosocket",
                transport="tcp",
                channel_id=call_uuid,
                data=call_uuid,
            )
            ext_id = str(ext.get("id") or call_uuid)
            state = AriCallState(
                call_uuid=call_uuid,
                caller_channel_id=caller_channel_id,
                bridge_id=bridge_id,
                ext_channel_id=ext_id,
                app_id=app_id,
            )
            with self._lock:
                self._ext_channels.add(ext_id)
                self._by_caller[caller_channel_id] = state
                self._by_uuid[call_uuid] = state

            self._join_or_wait(bridge_id, ext_id, call_uuid, caller_channel_id)
            return state
        except AriError:
            if state is not None:
                with self._lock:
                    self._by_uuid.pop(call_uuid, None)
                    self._by_caller.pop(caller_channel_id, None)
                    self._ext_channels.discard(state.ext_channel_id)
                self._teardown_bridge_and_ext(state)
            else:
                try:
                    self.client.destroy_bridge(bridge_id)
                except AriError:
                    log.exception("Could not clean up ARI bridge %s", bridge_id)
            raise

    def _join_or_wait(self, bridge_id: str, ext_id: str, call_uuid: str, caller_id: str) -> None:
        """The external media channel can still be outside the Stasis application when externalMedia
        returns; Asterisk 22 then answers addChannel with 422 "Channel not in Stasis application".
        Its own StasisStart event comes next on this same event thread, so waiting here would only hold up
        every other call: the channel joins when that event arrives (_on_stasis_start), and a timer sends the
        caller back to the dialplan if it never does."""
        try:
            self.client.add_channel(bridge_id, ext_id)
            return
        except AriError as e:
            if "not in Stasis" not in str(e):
                raise
        timer = threading.Timer(JOIN_TIMEOUT, self._join_timed_out, args=(ext_id,))
        timer.daemon = True
        with self._lock:
            self._pending_join[ext_id] = (bridge_id, call_uuid, caller_id, timer)
        timer.start()

    def _join_pending(self, ext_id: str, pending: tuple[str, str, str, Any]) -> None:
        bridge_id, call_uuid, caller_id, timer = pending
        timer.cancel()
        try:
            self.client.add_channel(bridge_id, ext_id)
            log.info("ARI channel %s joined bridge %s on its StasisStart", ext_id, bridge_id)
        except AriError:
            log.exception("ARI channel %s could not join bridge %s", ext_id, bridge_id)
            self._join_failed(call_uuid, caller_id, "ARI could not join the call to the media channel")

    def _join_timed_out(self, ext_id: str) -> None:
        with self._lock:
            pending = self._pending_join.pop(ext_id, None)
        if pending is not None:
            log.warning("ARI channel %s did not enter Stasis within %.0f s", ext_id, JOIN_TIMEOUT)
            self._join_failed(pending[1], pending[2], f"the media channel did not enter Stasis within {JOIN_TIMEOUT:.0f} s")

    def _join_failed(self, call_uuid: str, caller_id: str, text: str) -> None:
        """As a failed attach: the call log says why, the bridge goes, the caller returns to the dialplan."""
        if self.on_error is not None:
            try:
                self.on_error(call_uuid, text)
            except Exception:  # noqa: BLE001 - a log entry must never keep the caller from the dialplan
                pass
        with self._lock:
            state = self._by_uuid.pop(call_uuid, None)
            if state is not None:
                self._by_caller.pop(state.caller_channel_id, None)
                self._ext_channels.discard(state.ext_channel_id)
        if state is not None:
            self._teardown_bridge_and_ext(state)
        try:
            self.client.continue_in_dialplan(caller_id)
        except AriError:
            log.exception("Could not resume dialplan for ARI caller %s", caller_id)

    def _on_xfer_stasis_start(self, xfer_ch_id: str, call_uuid: str) -> None:
        with self._lock:
            pending = self._pending_xfers.get(call_uuid)
            if pending is not None:
                pending["channel_id"] = xfer_ch_id
                pending["answered"] = True
                pending["event"].set()

    def _on_stasis_end(self, event: dict[str, Any]) -> None:
        ch = event.get("channel") or {}
        ch_id = str(ch.get("id") or "")
        if not ch_id:
            return
        self._cleanup_by_channel(ch_id)

    def _on_channel_destroyed(self, event: dict[str, Any]) -> None:
        ch = event.get("channel") or {}
        ch_id = str(ch.get("id") or "")
        if not ch_id:
            return
        if self.outbound_navigator is not None:
            self.outbound_navigator.on_ari_event(event)
        with self._lock:
            for pending in self._pending_xfers.values():
                if pending.get("originated_id") == ch_id and not pending.get("answered"):
                    pending["failed"] = True
                    pending["event"].set()
        self._cleanup_by_channel(ch_id)

    def _cleanup_by_channel(self, ch_id: str) -> None:
        with self._lock:
            state = self._by_caller.pop(ch_id, None)
            if state is not None:
                self._by_uuid.pop(state.call_uuid, None)
                self._ext_channels.discard(state.ext_channel_id)
                pending = self._pending_join.pop(state.ext_channel_id, None)
                if pending is not None:      # the caller hung up before the media channel joined
                    pending[3].cancel()
        if state is None:
            return
        self._teardown_bridge_and_ext(state)

    def _teardown_bridge_and_ext(self, state: AriCallState) -> None:
        for fn in (
            lambda: self.client.remove_channel(state.bridge_id, state.ext_channel_id),
            lambda: self.client.hangup(state.ext_channel_id),
            lambda: self.client.destroy_bridge(state.bridge_id),
        ):
            try:
                fn()
            except AriError:
                pass

    def release_call(
        self,
        call_uuid: str,
        continue_dialplan: bool = True,
        context: str = "",
        extension: str = "",
        priority: int = 1,
    ) -> bool:
        """Detaches ExternalMedia/bridge and returns caller to dialplan (or hangs up)."""
        with self._lock:
            state = self._by_uuid.pop(call_uuid, None)
            if state is not None:
                self._by_caller.pop(state.caller_channel_id, None)
                self._ext_channels.discard(state.ext_channel_id)
        if state is None:
            return False
        if state.transferred:
            return False

        self._teardown_bridge_and_ext(state)
        try:
            if continue_dialplan:
                self.client.continue_in_dialplan(
                    state.caller_channel_id, context=context, extension=extension, priority=priority
                )
            else:
                self.client.hangup(state.caller_channel_id)
        except AriError as e:
            log.debug("release_call(%s) channel action failed: %s", call_uuid, e)
        return True

    def transfer_with_announce(
        self,
        call_uuid: str,
        endpoint: str,
        announce_media: str = "",
        caller_id: str = "",
        timeout: int = 30,
    ) -> bool:
        """Warm/announced transfer keeping AI on the line if target does not answer."""
        with self._lock:
            state = self._by_uuid.get(call_uuid)
            if state is None:
                return False
            ev = threading.Event()
            pending: dict[str, Any] = {"event": ev, "answered": False, "failed": False, "channel_id": "", "originated_id": ""}
            self._pending_xfers[call_uuid] = pending

        try:
            orig = self.client.originate(
                endpoint=endpoint,
                app=self.app_name,
                app_args=f"xfer,{call_uuid}",
                caller_id=caller_id,
                timeout=timeout,
            )
            with self._lock:
                pending["originated_id"] = str(orig.get("id") or "")
            ev.wait(timeout=float(timeout) + 2.0)
            if not pending["answered"] or pending["failed"] or not pending["channel_id"]:
                return False

            target_ch = str(pending["channel_id"])
            if announce_media:
                try:
                    self.client.play(target_ch, announce_media)
                except AriError:
                    pass

            self.client.add_channel(state.bridge_id, target_ch)
            with self._lock:
                state.transferred = True
                self._by_uuid.pop(call_uuid, None)
                self._by_caller.pop(state.caller_channel_id, None)
                self._ext_channels.discard(state.ext_channel_id)
            try:
                self.client.remove_channel(state.bridge_id, state.ext_channel_id)
                self.client.hangup(state.ext_channel_id)
            except AriError:
                pass
            return True
        except AriError as e:
            log.warning("transfer_with_announce(%s -> %s) failed: %s", call_uuid, endpoint, e)
            return False
        finally:
            with self._lock:
                self._pending_xfers.pop(call_uuid, None)

    def release_all(self) -> None:
        with self._lock:
            uuids = list(self._by_uuid.keys())
        for u in uuids:
            self.release_call(u, continue_dialplan=True)


def _ws_send_pong(sock: socket.socket, data: bytes) -> None:
    mask = os.urandom(4)
    n = len(data)
    header = bytes([0x8A, 0x80 | n]) + mask
    masked = bytes(b ^ mask[i & 3] for i, b in enumerate(data))
    sock.sendall(header + masked)


class AriRunner:
    """Background WebSocket event loop connecting Asterisk ARI to AriSessionManager."""

    def __init__(self, base_url: str, username: str, password: str, manager: AriSessionManager) -> None:
        self.base_url = base_url.rstrip("/")
        self.username = username
        self.password = password
        self.manager = manager
        self._stop = threading.Event()
        self._sock: socket.socket | None = None
        self._thread: threading.Thread | None = None
        self._reader: WebSocketReader | None = None

    def start(self) -> AriRunner:
        self._thread = threading.Thread(target=self._run, name="ari-events", daemon=True)
        self._thread.start()
        return self

    def close(self) -> None:
        self._stop.set()
        if self._sock is not None:
            try:
                self._sock.close()
            except OSError:
                pass
        self.manager.release_all()

    def _connect_ws(self) -> socket.socket:
        parsed = urllib.parse.urlparse(self.base_url)
        host = parsed.hostname or "127.0.0.1"
        port = parsed.port or (443 if parsed.scheme == "https" else 8088)
        base_path = parsed.path.rstrip("/")
        q = urllib.parse.urlencode({"app": self.manager.app_name, "api_key": f"{self.username}:{self.password}"})
        path = f"{base_path}/events?{q}"
        key = base64.b64encode(os.urandom(16)).decode("ascii")
        sock = socket.create_connection((host, port), timeout=5.0)
        req = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host}:{port}\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n\r\n"
        )
        try:
            sock.sendall(req.encode("ascii"))
            self._reader = WebSocketReader(sock)
            self._reader.read_upgrade(key)
        except (ConnectionError, OSError):
            sock.close()
            raise
        sock.settimeout(30.0)
        return sock

    def _run(self) -> None:
        backoff = 1.0
        while not self._stop.is_set():
            try:
                self._sock = self._connect_ws()
                backoff = 1.0
                log.info("ARI WebSocket connected (%s, app=%s)", self.base_url, self.manager.app_name)
                while not self._stop.is_set():
                    try:
                        opcode, payload = self._reader.read_frame()
                    except socket.timeout:
                        continue
                    if opcode == 0x8:
                        break
                    if opcode == 0x9:
                        _ws_send_pong(self._sock, payload)
                        continue
                    if opcode == 0x1:
                        ev = json.loads(payload.decode("utf-8"))
                        if isinstance(ev, dict):
                            try:
                                self.manager.handle_event(ev)
                            except AriError:
                                log.exception("ARI event handling failed: %s", ev.get("type"))
            except Exception as e:
                if not self._stop.is_set():
                    log.debug("ARI WebSocket disconnected: %s", e)
            finally:
                if self._sock is not None:
                    try:
                        self._sock.close()
                    except OSError:
                        pass
                    self._sock = None
            if not self._stop.is_set():
                self._stop.wait(backoff)
                backoff = min(backoff * 2.0, 15.0)
