"""HTTP API of aipbx-ai (standard library only).

Listens on 127.0.0.1 and trusts nobody: every request needs the shared token
(Authorization: Bearer <token>, compared in constant time), and requests
that carry X-Forwarded-For / Forwarded are refused — something proxied them,
and the portal never does. The one exception is POST /v1/calls/start, which
the dialplan calls with CURL(): it carries the dialplan key (an HMAC of the
token, see calls.py) instead and always answers 200 with the call's UUID or
an empty body. See the package docstring for the endpoints.
"""
import hmac
import json
import logging
import os
import platform
import re
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from . import __version__, sysinfo
from .calls import CallRegistry
from .voice_requests import APP_MAX, ConfigError
from .models import (ERROR, INSTALLED, LOADING, READY, DiskLimit, ModelBusy,
                     NotReady, ServiceBusy, UnknownModel, WrongKind)
from .custom import CatalogError
from .custom import model_id_for as catalog_model_id
from .wav import wav_bytes

log = logging.getLogger("aipbx_ai.server")

MAX_BODY = 128 * 1024          # 5000 characters, even fully \u-escaped, fit
MAX_TEXT = 5000
MAX_AUDIO = 4 * 1024 * 1024   # /v1/stt: 60 s of 16 kHz 16-bit stereo fits
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
_APP = re.compile(r"^/v1/apps/([0-9]{1,7})$")
_CALL = re.compile(r"^/v1/calls/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(/audio|/result)?$")
MAX_APP_BODY = 4 * 1024 * 1024  # an app config: up to 30 intents x 61 texts of 1000 characters


class ApiError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status
        self.message = message


class AiServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address, token, manager, catalog=None, calls=None):
        if not token:
            raise ValueError("empty token")
        self.token = token.encode() if isinstance(token, str) else bytes(token)
        self.manager = manager
        self.catalog = catalog
        self.calls = calls if calls is not None else CallRegistry(manager, self.token)
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
        if self._forwarded():
            raise ApiError(HTTPStatus.FORBIDDEN, "forwarded requests are not accepted")
        auth = self.headers.get("Authorization") or ""
        scheme, _, given = auth.partition(" ")
        if scheme.lower() != "bearer" or not hmac.compare_digest(given.strip().encode(), self.server.token):
            raise ApiError(HTTPStatus.UNAUTHORIZED, "missing or invalid token")

    def _body(self, limit=MAX_BODY):
        length = self.headers.get("Content-Length")
        if self.headers.get("Transfer-Encoding"):
            raise ApiError(HTTPStatus.BAD_REQUEST, "chunked bodies are not supported")
        if length is None or length.strip() == "":
            return {}
        if not length.strip().isdigit():
            raise ApiError(HTTPStatus.BAD_REQUEST, "invalid Content-Length")
        n = int(length)
        if n > limit:
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

    def _forwarded(self):
        return self.headers.get("X-Forwarded-For") is not None or self.headers.get("Forwarded") is not None

    def _call_start(self):
        """POST /v1/calls/start from the dialplan: 200 text/plain, the UUID or empty."""
        answer = ""
        try:
            length = (self.headers.get("Content-Length") or "").strip()
            if self._forwarded():
                log.warning("call refused: forwarded request")
            elif self.headers.get("Transfer-Encoding") or not length.isdigit():
                log.warning("call refused: no Content-Length")
            elif int(length) > MAX_BODY:
                log.warning("call refused: body too large")
                self.close_connection = True
            else:
                answer = self.server.calls.start(self.rfile.read(int(length)))
        except Exception:  # noqa: BLE001 - the dialplan only ever sees an empty answer
            log.exception("call start failed")
            answer = ""
        self._send(HTTPStatus.OK, answer.encode("ascii"), "text/plain; charset=utf-8")

    def _app(self, method, app_id):
        """PUT/GET/DELETE /v1/apps/<id>: a voice_requests application config."""
        apps = self.server.calls.apps
        if not 1 <= app_id <= APP_MAX:
            raise ApiError(HTTPStatus.NOT_FOUND, "not found")
        if method == "PUT":
            try:
                config = apps.put(app_id, self._body(MAX_APP_BODY))
            except ConfigError as e:
                raise ApiError(HTTPStatus.BAD_REQUEST, str(e)) from None
            return self._json(HTTPStatus.OK, dict(config, id=app_id))
        if method == "GET":
            config, _ = apps.get(app_id)
            if config is None:
                raise ApiError(HTTPStatus.NOT_FOUND, "no config for this application")
            return self._json(HTTPStatus.OK, dict(config, id=app_id))
        if method == "DELETE":
            return self._json(HTTPStatus.OK, {"deleted": apps.delete(app_id)})
        raise ApiError(HTTPStatus.METHOD_NOT_ALLOWED, "method not allowed")

    def _call_read(self, call_uuid, part):
        """GET /v1/calls/<uuid>[/audio|/result].

        /result is for the dialplan: ?key=<dialplan key>, always 200 text/plain
        (the intent id, "none", or empty). The call itself and its audio take
        the bearer token or the dialplan key (?key=, for the portal's script
        that the dialplan starts as the asterisk user)."""
        from urllib.parse import parse_qs, urlsplit

        calls = self.server.calls
        key = (parse_qs(urlsplit(self.path).query).get("key") or [""])[0]
        if part == "/result":
            answer = ""
            if self._forwarded():
                log.warning("call result refused: forwarded request")
            else:
                answer = calls.result_for_dialplan(call_uuid, key)
            return self._send(HTTPStatus.OK, answer.encode("ascii", "replace"), "text/plain; charset=utf-8")
        if self._forwarded():
            raise ApiError(HTTPStatus.FORBIDDEN, "forwarded requests are not accepted")
        if self.headers.get("Authorization") is not None or not key:
            self._authorize()
        elif not calls.key_ok(key):
            raise ApiError(HTTPStatus.UNAUTHORIZED, "missing or invalid token")
        result = calls.results.get(call_uuid)
        if result is None:
            raise ApiError(HTTPStatus.NOT_FOUND, "unknown call")
        if part == "/audio":
            path = calls.results.audio_path(call_uuid)
            if path is None:
                raise ApiError(HTTPStatus.NOT_FOUND, "no audio for this call")
            return self._send(HTTPStatus.OK, path.read_bytes(), "audio/wav")
        return self._json(HTTPStatus.OK, result)

    def _dispatch(self, method):
        try:
            path = self.path.split("?", 1)[0]
            if method == "POST" and path == "/v1/calls/start":
                return self._call_start()
            call = _CALL.match(path)
            if call and method == "GET":
                return self._call_read(call.group(1), call.group(2))
            if call:
                self._authorize()
                raise ApiError(HTTPStatus.METHOD_NOT_ALLOWED, "method not allowed")
            self._authorize()
            if method == "GET" and path == "/v1/apps":
                return self._json(HTTPStatus.OK, {"apps": self.server.calls.apps.list()})
            app = _APP.match(path)
            if app:
                return self._app(method, int(app.group(1)))
            if method == "GET" and path == "/v1/health":
                return self._json(HTTPStatus.OK, self._health())
            if method == "GET" and path == "/v1/models":
                return self._json(HTTPStatus.OK, {"models": self.server.manager.describe_all(),
                                                  "disk": self.server.manager.disk()})
            if method == "POST" and path == "/v1/tts":
                return self._tts(self._body())
            if method == "POST" and path == "/v1/stt":
                return self._stt()
            if method == "GET" and path == "/v1/calls":
                return self._json(HTTPStatus.OK, self.server.calls.snapshot())
            if method == "GET" and path == "/v1/catalog/piper":
                return self._piper_catalog()
            if method == "POST" and path == "/v1/models/add":
                return self._add_model(self._body())
            m = _MODEL_ACTION.match(path)
            if m and method == "POST":
                return self._model_action(m.group(1), m.group(2), self._body())
            if path in ("/v1/health", "/v1/models", "/v1/tts", "/v1/calls", "/v1/calls/start", "/v1/apps") or m:
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

    def _stt(self):
        """POST /v1/stt?model=<id>, body: a WAV file (Content-Type audio/wav), up to 60 s."""
        from urllib.parse import parse_qs, urlsplit

        model_id = (parse_qs(urlsplit(self.path).query).get("model") or [""])[0]
        if not model_id:
            raise ApiError(HTTPStatus.BAD_REQUEST, "model is required")
        if (self.headers.get("Content-Type") or "").split(";")[0].strip() not in ("audio/wav", "audio/x-wav", "audio/wave"):
            raise ApiError(HTTPStatus.BAD_REQUEST, "send the audio as audio/wav")
        length = (self.headers.get("Content-Length") or "").strip()
        if not length.isdigit():
            raise ApiError(HTTPStatus.BAD_REQUEST, "invalid Content-Length")
        if int(length) > MAX_AUDIO:
            raise ApiError(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, "audio too large")
        wav = self.rfile.read(int(length))
        try:
            started = time.perf_counter()
            result = self.server.manager.transcribe(model_id, wav)
            result["ms"] = int(round((time.perf_counter() - started) * 1000))
        except NotReady:
            raise ApiError(HTTPStatus.CONFLICT, "model not ready") from None
        except WrongKind:
            raise ApiError(HTTPStatus.BAD_REQUEST, "model is not a speech-to-text model") from None
        except ServiceBusy:
            raise ApiError(HTTPStatus.SERVICE_UNAVAILABLE, "too many requests waiting") from None
        except ValueError as e:
            raise ApiError(HTTPStatus.BAD_REQUEST, str(e)) from None
        return self._json(HTTPStatus.OK, result)

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
