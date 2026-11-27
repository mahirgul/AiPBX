"""The calls the service has: admission (how many at once, per application), the sessions waiting for their
AudioSocket connection and those playing, what happens when one ends, and the housekeeping that clears them out.
"""
import logging
import hmac
import re
import threading
import time
from collections import deque
from . import secret_refs
from .call_media import SAMPLE_RATE
from .call_session import CallSession, DONE, PLAYING, PREPARED
from .call_text import APP_MAX, MAX_FILLED, MAX_ID_FIELD, MAX_PER_APP_DEFAULT, MAX_PER_APP_LIMIT, MAX_UNIQUEID, Refused, _FIELDS, _ID_FIELD, _UNIQUEID, _id_field, _int_field, _lookup, _speed, _text, dialplan_key, display_name, fetch_values, lookup_url, parse_form, render
from .calllog import CallLog
from .models import NotReady, ServiceBusy, UnknownModel, WrongKind
from .voice_requests import APP_TYPE as VOICE_REQUESTS, AppStore, ResultStore, call_language, choose_greeting


log = logging.getLogger("aipbx_ai.calls")


MAX_LIVE = 100               # live calls of all applications together


SESSION_TTL = 30.0           # a prepared call nobody connects to expires


MAX_CALL_SECONDS = 300.0     # live call duration ceiling


FINISHED_KEEP = 50


FINISHED_SECONDS = 600


CLEANUP_EVERY = 300.0        # seconds between deletions of old voice_requests results


class CallRegistry:
    """Prepared and live calls, the last finished ones, and /v1/calls/start."""

    def __init__(self, manager, token, ttl=SESSION_TTL, max_live=MAX_LIVE, data_dir=None, max_call_seconds=MAX_CALL_SECONDS):
        self.manager = manager
        self.key = dialplan_key(token).encode()
        self.apps = AppStore(manager, data_dir)
        self.results = ResultStore(data_dir)
        self.log = CallLog(data_dir)
        self._next_cleanup = 0.0
        self.ttl = ttl
        self.max_live = max_live
        self.max_call_seconds = float(max_call_seconds)
        self._draining = False
        self._mu = threading.Lock()
        self._live = {}                # uuid -> CallSession (prepared or playing)
        self._finished = deque(maxlen=FINISHED_KEEP)
        self.on_finish = None
        self.ari_manager = None
        self.outbound_navigator = None
        self._stop = threading.Event()
        self._sweeper = threading.Thread(target=self._sweep_loop, name="call-sweeper", daemon=True)
        self._sweeper.start()

    def close(self):
        self._stop.set()

    def key_ok(self, given):
        """Constant-time check of a dialplan key (any case)."""
        given = (given or "").strip().lower().encode("ascii", "replace")
        return hmac.compare_digest(given, self.key)

    def digit_from_ari(self, call_uuid, digit):
        with self._mu:
            session = self._live.get(call_uuid)
        if session is not None:
            session.digit_in(digit)

    def register_listen_session(self, session):
        with self._mu:
            self._live[session.uuid] = session
        return session

    # ---- start --------------------------------------------------------

    def start(self, raw_body):
        """Returns the new call's UUID, or "" when refused (reason in the journal)."""
        form = None
        try:
            form = parse_form(raw_body)
            if not self.key_ok(form.get("key", "")):
                raise Refused("wrong dialplan key")
            if "text" not in form and "model" not in form:
                return self._start_voice_requests(form)
            return self._start(form)
        except Refused as e:
            app = (form or {}).get("app", "?")[:10]
            log.warning("call refused: app=%s reason=%s", app if app.isdigit() else "?", e)
            return ""
        except Exception:  # noqa: BLE001 - the dialplan only ever sees an empty answer
            log.exception("call refused: internal error")
            return ""

    def _start(self, form):
        unknown = set(form) - set(_FIELDS)
        if unknown:
            log.info("call: ignoring unknown fields %s", ",".join(sorted(unknown))[:100])
        app = _int_field(form, "app", 1, APP_MAX)
        limit = _int_field(form, "max", 1, MAX_PER_APP_LIMIT, MAX_PER_APP_DEFAULT)
        model = form.get("model", "").strip()
        if not re.match(r"^[a-z0-9][a-z0-9._-]{0,63}$", model):
            raise Refused("invalid model")
        speed = _speed(form)
        text = _text(form, "text", True)
        fallback = _text(form, "fallback", False)
        lookup = _lookup(form)
        caller = _id_field(form, "caller", _ID_FIELD, MAX_ID_FIELD)
        did = _id_field(form, "did", _ID_FIELD, MAX_ID_FIELD)
        uniqueid = _id_field(form, "uniqueid", _UNIQUEID, MAX_UNIQUEID)

        try:
            info = self.manager.describe(model)
        except UnknownModel:
            raise Refused(f"unknown model {model}") from None
        if info["kind"] != "tts":
            raise Refused(f"model {model} is not a text-to-speech model")
        if info["state"] != "ready":
            raise Refused(f"model {model} is not ready ({info['state']})")

        session = CallSession(app, model, caller, did, uniqueid)
        self._reserve(session, limit)
        try:
            session.chars, spoken = self._prepare_text(session, text, fallback, lookup)
            try:
                pcm = self.manager.synthesize(model, spoken, speed, SAMPLE_RATE)
            except NotReady:
                raise Refused(f"model {model} is not ready") from None
            except WrongKind:
                raise Refused(f"model {model} is not a text-to-speech model") from None
            except ServiceBusy:
                raise Refused("service busy: too many requests waiting for the model") from None
            except Exception as e:  # noqa: BLE001
                log.exception("call synthesis failed")
                raise Refused(f"synthesis failed: {type(e).__name__}") from None
            if not pcm or len(pcm) % 2:
                raise Refused("synthesis returned no audio")
            session.pcm = bytes(pcm)
            session.audio_seconds = len(pcm) / 2 / SAMPLE_RATE
            session.prepare_ms = int((time.monotonic() - session.created) * 1000)
        except BaseException:
            with self._mu:
                self._live.pop(session.uuid, None)
            raise
        return session.uuid

    def _start_voice_requests(self, form):
        """A call of a pushed voice_requests application: the greeting is made ready."""
        unknown = set(form) - set(_FIELDS) - {"context", "verified_customer", "caller_name", "linkedid"}
        if unknown:
            log.info("call: ignoring unknown fields %s", ",".join(sorted(unknown))[:100])
        app = _int_field(form, "app", 1, APP_MAX)
        limit = _int_field(form, "max", 1, MAX_PER_APP_LIMIT, MAX_PER_APP_DEFAULT)
        config, version = self.apps.get(app)
        if config is None:
            raise Refused("no text and no voice_requests config for this application")
        realtime = config.get("mode") == "realtime"      # the provider speaks: no voice model, no prompts here
        model = config["tts_model"]
        if not realtime and not model.startswith("cloud:"):       # a cloud provider: nothing to load here
            try:
                info = self.manager.describe(model)
            except UnknownModel:
                raise Refused(f"unknown model {model}") from None
            if info["kind"] != "tts":
                raise Refused(f"model {model} has the wrong kind")
            if info["state"] != "ready":
                raise Refused(f"model {model} is not ready ({info['state']})")
        session = CallSession(app, config["tts_model"], _id_field(form, "caller", _ID_FIELD, MAX_ID_FIELD),
                              _id_field(form, "did", _ID_FIELD, MAX_ID_FIELD),
                              _id_field(form, "uniqueid", _UNIQUEID, MAX_UNIQUEID), kind=VOICE_REQUESTS)
        session.config, session.version = config, version
        session.lang, session.lang_name, session.lang_locale = call_language(config, form.get("lang"))
        if form.get("linkedid"):
            session.linkedid = _id_field(form, "linkedid", _UNIQUEID, MAX_UNIQUEID)
        # Handed over by another assistant of a conversation: its summary (SyncAiApps.php sends AI_SUMMARY).
        session.context = re.sub(r"[\x00-\x1f\x7f]", " ", form.get("context", ""))[:500].strip()
        customer = form.get("verified_customer", "")
        if re.fullmatch(r"[A-Za-z0-9._@-]{1,64}", customer):
            session.verified_customer = customer
        self._reserve(session, limit)
        try:
            raw_name, _ = secret_refs.caller_info(session.caller)
            session.caller_name = display_name(raw_name)
            session.greeting = choose_greeting(config, session.lang, session.caller_name)
            if realtime:
                session.prepare_ms = int((time.monotonic() - session.created) * 1000)
                return session.uuid
            try:
                template = session.greeting
                greeting = self.apps.greeting_text({"greeting": template}, session.caller_name, session.caller, session.did)
                session.pcm = self.apps.greeting_speech(
                    app, version, config, template, session.caller_name, session.caller, session.did, session.lang
                )
            except NotReady:
                raise Refused(f"model {config['tts_model']} is not ready") from None
            except ServiceBusy:
                raise Refused("service busy: too many requests waiting for the model") from None
            except Exception as e:  # noqa: BLE001
                log.exception("call synthesis failed")
                raise Refused(f"synthesis failed: {type(e).__name__}") from None
            if not session.pcm:
                raise Refused("synthesis returned no audio")
            session.chars = len(greeting)
            session.audio_seconds = len(session.pcm) / 2 / SAMPLE_RATE
            session.prepare_ms = int((time.monotonic() - session.created) * 1000)
        except BaseException:
            with self._mu:
                self._live.pop(session.uuid, None)
            raise
        self.apps.warm(app, version, config)     # retry, not_understood and replies, in the background
        return session.uuid

    def result_for_dialplan(self, call_uuid, key):
        """GET /v1/calls/<uuid>/result: the intent id, "none", or "" (unknown call or wrong key)."""
        if not self.key_ok(key):
            return ""
        result = self.results.get(call_uuid)
        if result is None:
            return ""
        if result.get("result") == "ended":
            return "_end"              # conversation said goodbye: the dialplan hangs up
        return result.get("route_id") or result.get("intent") or "none"

    def summary_for_dialplan(self, call_uuid, key):
        """GET /v1/calls/<uuid>/summary: what a conversation handed over, or ""."""
        if not self.key_ok(key):
            return ""
        result = self.results.get(call_uuid)
        return (result or {}).get("summary") or ""

    def drain(self, enabled=True):
        """When draining, new calls are refused while active calls finish."""
        with self._mu:
            self._draining = bool(enabled)
            return {"draining": self._draining, "active": len(self._live)}

    def _reserve(self, session, limit):
        with self._mu:
            if self._draining:
                raise Refused("draining")
            if len(self._live) >= self.max_live:
                raise Refused(f"service busy: {self.max_live} live calls")
            n = sum(1 for s in self._live.values() if s.app == session.app)
            if n >= limit:
                raise Refused(f"application has {n} live calls (max {limit})")
            if not session.max_call_seconds:
                session.max_call_seconds = self.max_call_seconds
            self._live[session.uuid] = session

    def _prepare_text(self, session, text, fallback, lookup):
        values, failed = {}, False
        if lookup:
            url = lookup_url(lookup, session.caller, session.did, session.app)
            try:
                values = fetch_values(url)
                session.lookup = "ok"
            except ValueError as e:
                failed = True
                session.lookup = f"failed: {e}"
                log.info("call app=%d: lookup failed (%s)", session.app, e)
        if "{caller_name}" in text or "{caller_name}" in fallback:
            values["caller_name"] = display_name(secret_refs.caller_name(session.caller))
        spoken, missing = render(text, values)
        if (failed or missing) and fallback:
            spoken, _ = render(fallback, values)
        if not spoken:
            raise Refused("nothing to say (empty text)")
        if len(spoken) > MAX_FILLED:
            raise Refused(f"text with values is longer than {MAX_FILLED} characters")
        return len(spoken), spoken

    # ---- AudioSocket side -------------------------------------------

    def claim(self, call_uuid):
        """The session for a new AudioSocket connection (once), or None."""
        with self._mu:
            session = self._live.get(call_uuid)
            if session is None or session.state != PREPARED:
                return None
            session.state = PLAYING
            session.connected = time.monotonic()
            return session

    def note(self, call_uuid, text):
        """A problem outside the call's own thread (ARI) for the call log."""
        with self._mu:
            s = self._live.get(call_uuid)
        if s is not None:
            s.note(text)

    def finish(self, session, result):
        with self._mu:
            if session.state == DONE:
                return
            self._live.pop(session.uuid, None)
            session.state = DONE
            session.result = result
            session.ended = time.time()
            session.ended_mono = time.monotonic()
            session.pcm = b""          # free the audio
            session.listen_q = None
            self._finished.append(session)
        self.log.add(session.record())
        played = session.sent_bytes / 2 / SAMPLE_RATE
        wall = session.ended_mono - session.connected if session.connected else 0.0
        log.info("call app=%d caller=%s chars=%d audio=%.1fs prepared=%dms played=%.1fs in %.2fs "
                 "lookup=%s dtmf=%s result=%s",
                 session.app, session.caller or "-", session.chars, session.audio_seconds,
                 session.prepare_ms, played, wall, session.lookup or "-", "".join(session.dtmf) or "-", result)
        cb = self.on_finish
        if cb is not None:
            try:
                cb(session.uuid)
            except Exception as e:
                log.debug("on_finish(%s) failed: %s", session.uuid, e)

    # ---- housekeeping and the live view -----------------------------

    def sweep(self):
        now = time.monotonic()
        with self._mu:
            expired = [s for s in self._live.values()
                       if s.state == PREPARED and now - s.created > self.ttl]
            timed_out = [s for s in self._live.values()
                         if s.state == PLAYING and s.connected is not None
                         and now - s.connected > (s.max_call_seconds or self.max_call_seconds)]
        for s in expired:
            self.finish(s, "expired")
        for s in timed_out:
            self.finish(s, "timeout")

    def _sweep_loop(self):
        while not self._stop.wait(1.0):
            try:
                self.sweep()
                if time.monotonic() >= self._next_cleanup:
                    self._next_cleanup = time.monotonic() + CLEANUP_EVERY
                    removed = self.results.cleanup()
                    if removed:
                        log.info("deleted %d voice_requests result file(s) older than 24 h", removed)
            except Exception:  # noqa: BLE001
                log.exception("call sweeper failed")

    def snapshot(self):
        self.sweep()
        cutoff = time.time() - FINISHED_SECONDS
        with self._mu:
            live = list(self._live.values())
            finished = [s for s in self._finished if s.ended and s.ended >= cutoff]
        per_app = {}
        for s in live:
            per_app[str(s.app)] = per_app.get(str(s.app), 0) + 1
        calls = [s.describe() for s in sorted(live, key=lambda s: s.created)]
        calls += [s.describe() for s in reversed(finished)]
        return {"calls": calls, "per_app": per_app, "draining": self._draining, "active": len(live)}

    def live_count(self, app=None):
        with self._mu:
            return sum(1 for s in self._live.values() if app is None or s.app == app)
