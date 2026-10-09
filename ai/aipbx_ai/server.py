"""HTTP API of aipbx-ai (standard library only).

Listens on 127.0.0.1 and trusts nobody: every request needs the shared token
(Authorization: Bearer <token>, compared in constant time), and requests
that carry X-Forwarded-For / Forwarded are refused — something proxied them,
and the portal never does. See the package docstring for the endpoints.
"""
import hmac
import json
import logging
import os
import platform
import re
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from . import __version__, sysinfo
from .models import (ERROR, INSTALLED, LOADING, READY, DiskLimit, ModelBusy,
                     NotReady, ServiceBusy, UnknownModel, WrongKind)
from .custom import CatalogError
from .custom import model_id_for as catalog_model_id
from .wav import wav_bytes

log = logging.getLogger("aipbx_ai.server")

MAX_BODY = 128 * 1024          # 5000 characters, even fully \u-escaped, fit
MAX_TEXT = 5000
SAMPLE_RATES = (8000, 16000, 24000, 48000)
SPEED_MIN, SPEED_MAX = 0.5, 2.0
BENCHMARK_RATE = 8000
# The speed test reads a sentence in the model's own language (a voice that
# reads another language is slower and sounds wrong, which skews the figure).
BENCHMARK_TEXTS = {
    "tr": ("Merhaba, bizi aradığınız için teşekkür ederiz. Görüşmeniz kalite "
           "standartları gereği kayıt altına alınabilir. Lütfen hatta kalın, "
           "ilk uygun temsilcimiz size yardımcı olacaktır."),
    "de": ("Guten Tag, vielen Dank für Ihren Anruf. Ihr Gespräch kann zur Qualitätssicherung "
           "aufgezeichnet werden. Bitte bleiben Sie in der Leitung, der nächste freie Mitarbeiter "
           "ist gleich für Sie da."),
    "en": ("Hello, thank you for calling. Your call may be recorded for quality purposes. "
           "Please stay on the line, the next available agent will be with you shortly."),
}
BENCHMARK_TEXT = BENCHMARK_TEXTS["tr"]


def benchmark_text(languages):
    for lang in languages or ():
        text = BENCHMARK_TEXTS.get(str(lang).split("-")[0].split("_")[0].lower())
        if text:
            return text
    return BENCHMARK_TEXTS["en"]

_MODEL_ACTION = re.compile(r"^/v1/models/([a-z0-9][a-z0-9._-]{0,63})/(install|remove|benchmark|run|stop)$")


class ApiError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status
        self.message = message


class AiServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address, token, manager, catalog=None):
        if not token:
            raise ValueError("empty token")
        self.token = token.encode() if isinstance(token, str) else bytes(token)
        self.manager = manager
        self.catalog = catalog
        self.cpu = sysinfo.CpuMeter()
        super().__init__(address, Handler)


class Handler(BaseHTTPRequestHandler):
    server_version = "aipbx-ai/" + __version__
    sys_version = ""
    # Seconds a client may take to send its request; a long synthesis is not
    # limited by this (no socket I/O while the model runs).
    timeout = 60

    # ---- plumbing --------------------------------------------------------

    def log_message(self, fmt, *args):  # noqa: D401 - journal, no client data
        log.info("%s %s", self.address_string(), fmt % args)

    def _send(self, status, body, content_type):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _json(self, status, obj):
        self._send(status, json.dumps(obj, ensure_ascii=False).encode(), "application/json; charset=utf-8")

    def _error(self, status, message):
        self._json(status, {"error": message})

    def _authorize(self):
        if self.headers.get("X-Forwarded-For") is not None or self.headers.get("Forwarded") is not None:
            raise ApiError(HTTPStatus.FORBIDDEN, "forwarded requests are not accepted")
        auth = self.headers.get("Authorization") or ""
        scheme, _, given = auth.partition(" ")
        if scheme.lower() != "bearer" or not hmac.compare_digest(given.strip().encode(), self.server.token):
            raise ApiError(HTTPStatus.UNAUTHORIZED, "missing or invalid token")

    def _body(self):
        length = self.headers.get("Content-Length")
        if self.headers.get("Transfer-Encoding"):
            raise ApiError(HTTPStatus.BAD_REQUEST, "chunked bodies are not supported")
        if length is None or length.strip() == "":
            return {}
        if not length.strip().isdigit():
            raise ApiError(HTTPStatus.BAD_REQUEST, "invalid Content-Length")
        n = int(length)
        if n > MAX_BODY:
            raise ApiError(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, "request body too large")
        if n == 0:
            return {}
        raw = self.rfile.read(n)
        try:
            data = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            raise ApiError(HTTPStatus.BAD_REQUEST, "invalid JSON body") from None
        if not isinstance(data, dict):
            raise ApiError(HTTPStatus.BAD_REQUEST, "the JSON body must be an object")
        return data

    def _dispatch(self, method):
        try:
            self._authorize()
            path = self.path.split("?", 1)[0]
            if method == "GET" and path == "/v1/health":
                return self._json(HTTPStatus.OK, self._health())
            if method == "GET" and path == "/v1/models":
                return self._json(HTTPStatus.OK, {"models": self.server.manager.describe_all(),
                                                  "disk": self.server.manager.disk()})
            if method == "POST" and path == "/v1/tts":
                return self._tts(self._body())
            if method == "GET" and path == "/v1/catalog/piper":
                return self._piper_catalog()
            if method == "POST" and path == "/v1/models/add":
                return self._add_model(self._body())
            m = _MODEL_ACTION.match(path)
            if m and method == "POST":
                return self._model_action(m.group(1), m.group(2), self._body())
            if path in ("/v1/health", "/v1/models", "/v1/tts") or m:
                raise ApiError(HTTPStatus.METHOD_NOT_ALLOWED, "method not allowed")
            raise ApiError(HTTPStatus.NOT_FOUND, "not found")
        except ApiError as e:
            self._error(e.status, e.message)
        except UnknownModel:
            self._error(HTTPStatus.NOT_FOUND, "unknown model")
        except Exception:  # noqa: BLE001 - never leak a traceback to the client
            log.exception("request failed: %s %s", method, self.path)
            self._error(HTTPStatus.INTERNAL_SERVER_ERROR, "internal error")

    def do_GET(self):  # noqa: N802
        self._dispatch("GET")

    def do_POST(self):  # noqa: N802
        self._dispatch("POST")

    def do_PUT(self):  # noqa: N802
        self._dispatch("PUT")

    def do_DELETE(self):  # noqa: N802
        self._dispatch("DELETE")

    # ---- endpoints -------------------------------------------------------

    def _health(self):
        total, available = sysinfo.ram_mb()
        return {
            "ok": True,
            "version": __version__,
            "python": platform.python_version(),
            "onnxruntime": sysinfo.runtime_version(),
            "cpu": {"cores": os.cpu_count() or 1, "model": sysinfo.cpu_model()},
            "ram": {"total_mb": total, "available_mb": available},
            "process": {"rss_mb": sysinfo.rss_mb(), "cpu_percent": self.server.cpu.percent()},
        }

    def _known_files(self):
        known = {}
        for m in self.server.manager.describe_all():
            for path in self.server.manager.source_files(m["id"]):
                known[path] = m["id"]
        return known

    def _piper_catalog(self):
        catalog = self.server.catalog
        if catalog is None:
            raise ApiError(HTTPStatus.NOT_FOUND, "not found")
        try:
            voices = catalog.piper_voices(self._known_files())
        except CatalogError as e:
            raise ApiError(HTTPStatus.BAD_GATEWAY, str(e)) from None
        return self._json(HTTPStatus.OK, {"voices": voices})

    def _add_model(self, body):
        catalog, manager = self.server.catalog, self.server.manager
        if catalog is None:
            raise ApiError(HTTPStatus.NOT_FOUND, "not found")
        if body.get("engine") != "piper":
            raise ApiError(HTTPStatus.BAD_REQUEST, "engine must be piper")
        if body.get("accept_license") is not True:
            raise ApiError(HTTPStatus.BAD_REQUEST, "the model license must be accepted (accept_license: true)")
        key = body.get("key")
        if isinstance(key, str) and catalog_model_id(key) in self._known_files().values():
            raise ApiError(HTTPStatus.CONFLICT, "this voice is already in the list")
        try:
            spec = catalog.piper_spec(key)
        except CatalogError as e:
            raise ApiError(HTTPStatus.BAD_REQUEST if str(e) == "unknown voice" else HTTPStatus.BAD_GATEWAY, str(e)) from None
        known = self._known_files()
        if any(path in known for path in spec.source["files"]) or manager.has(spec.id):
            raise ApiError(HTTPStatus.CONFLICT, "this voice is already in the list")
        if manager.disk()["used_mb"] + spec.download_mb > manager.disk_limit_mb:
            raise ApiError(HTTPStatus.INSUFFICIENT_STORAGE, "the disk limit for models would be exceeded")
        catalog.save(spec)
        manager.add(spec)
        try:
            state = manager.install(spec.id)
        except DiskLimit:
            raise ApiError(HTTPStatus.INSUFFICIENT_STORAGE, "the disk limit for models would be exceeded") from None
        return self._json(HTTPStatus.ACCEPTED, {"id": spec.id, "state": state})

    def _model_action(self, model_id, action, body):
        manager = self.server.manager
        if action == "install":
            if body.get("accept_license") is not True:
                raise ApiError(HTTPStatus.BAD_REQUEST, "the model license must be accepted (accept_license: true)")
            try:
                state = manager.install(model_id)
            except DiskLimit:
                raise ApiError(HTTPStatus.INSUFFICIENT_STORAGE, "the disk limit for models would be exceeded") from None
            return self._json(HTTPStatus.ACCEPTED, {"state": state})
        if action == "run":
            if manager.state(model_id) not in (INSTALLED, READY, LOADING, ERROR):
                raise ApiError(HTTPStatus.CONFLICT, "the model is not downloaded")
            if manager.state(model_id) == ERROR and not manager.files_present(model_id):
                raise ApiError(HTTPStatus.CONFLICT, "the model is not downloaded")
            return self._json(HTTPStatus.ACCEPTED, {"state": manager.install(model_id)})
        if action == "stop":
            try:
                state = manager.stop(model_id)
            except ModelBusy:
                raise ApiError(HTTPStatus.CONFLICT, "model is busy downloading or loading") from None
            return self._json(HTTPStatus.OK, {"state": state})
        if action == "remove":
            try:
                state = manager.remove(model_id)
            except ModelBusy:
                raise ApiError(HTTPStatus.CONFLICT, "model is busy downloading or loading") from None
            return self._json(HTTPStatus.OK, {"state": state})
        # benchmark
        try:
            result = manager.benchmark(model_id, benchmark_text(manager.describe(model_id)["languages"]), BENCHMARK_RATE)
        except NotReady:
            raise ApiError(HTTPStatus.CONFLICT, "model not ready") from None
        except WrongKind:
            raise ApiError(HTTPStatus.BAD_REQUEST, "this model has no speed test") from None
        except ServiceBusy:
            raise ApiError(HTTPStatus.SERVICE_UNAVAILABLE, "too many requests waiting") from None
        return self._json(HTTPStatus.OK, result)

    def _tts(self, body):
        model_id = body.get("model")
        if not isinstance(model_id, str) or not model_id:
            raise ApiError(HTTPStatus.BAD_REQUEST, "model is required")
        text = body.get("text")
        if not isinstance(text, str):
            raise ApiError(HTTPStatus.BAD_REQUEST, "text must be a string")
        text = text.strip()
        if not 1 <= len(text) <= MAX_TEXT:
            raise ApiError(HTTPStatus.BAD_REQUEST, f"text must be 1 to {MAX_TEXT} characters")
        speed = body.get("speed", 1.0)
        if isinstance(speed, bool) or not isinstance(speed, (int, float)) or not SPEED_MIN <= speed <= SPEED_MAX:
            raise ApiError(HTTPStatus.BAD_REQUEST, f"speed must be a number from {SPEED_MIN} to {SPEED_MAX}")
        rate = body.get("sample_rate", 8000)
        if isinstance(rate, bool) or rate not in SAMPLE_RATES:
            raise ApiError(HTTPStatus.BAD_REQUEST, "sample_rate must be one of 8000, 16000, 24000, 48000")
        rate = int(rate)
        try:
            pcm = self.server.manager.synthesize(model_id, text, float(speed), rate)
        except NotReady:
            raise ApiError(HTTPStatus.CONFLICT, "model not ready") from None
        except WrongKind:
            raise ApiError(HTTPStatus.BAD_REQUEST, "model is not a text-to-speech model") from None
        except ServiceBusy:
            raise ApiError(HTTPStatus.SERVICE_UNAVAILABLE, "too many requests waiting") from None
        except UnknownModel:
            raise
        except Exception:  # noqa: BLE001
            log.exception("synthesis failed (%d characters)", len(text))
            raise ApiError(HTTPStatus.INTERNAL_SERVER_ERROR, "synthesis failed") from None
        self._send(HTTPStatus.OK, wav_bytes(pcm, rate), "audio/wav")
