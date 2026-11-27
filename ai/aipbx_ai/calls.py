"""Live calls of AI applications (roadmap 5, AI applications).

The first application type is "announcement with values": a caller dials
the application's number, Asterisk answers and asks this service to prepare
the call, then connects the call's audio with AudioSocket(); the service
speaks the prepared text in real time and hangs up the socket, and the
dialplan continues to the application's destination.

The portal writes this dialplan for every application (the contract):

    [aipbx-ai-app-<id>]
    exten => s,1,Answer()
     same => n,Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=<id>&key=<key>&...)})
     same => n,GotoIf($["${LEN(${AI_CALL})}" != "36"]?done)
     same => n,AudioSocket(${AI_CALL},127.0.0.1:8791)
     same => n(done),Goto(<destination>)

1. ``POST /v1/calls/start`` (application/x-www-form-urlencoded, as CURL()
   sends it) checks the dialplan key, renders the text (optionally filled
   from a JSON lookup), synthesises it at 8 kHz and answers ``200 text/plain``
   with only the call's UUID, or with an empty body when the call is refused
   (the reason goes to the journal). It never answers an error status: an
   empty AI_CALL simply skips the AI part of the call.
2. Asterisk connects to the AudioSocket server (127.0.0.1:8791), sends the
   UUID, and the service plays the audio in 20 ms frames paced in real time,
   then sends "terminate" (0x00) so AudioSocket() returns 0 and the dialplan
   continues. Audio and DTMF from the caller are parsed and kept in the
   session.

The second type, "voice_requests" (a pushed config, no text in the start
request), listens to the caller: see VoiceRequestsCall and voice_requests.py.

The dialplan cannot read /etc/aipbx/ai.token, so /v1/calls/start does not
take the bearer token; ``key`` is the lowercase hex HMAC-SHA256 of
b"aipbx-dialplan" under the token (dialplan_key()), which the portal
computes from the same file.

AudioSocket protocol (https://docs.asterisk.org/Configuration/Channel-Drivers/AudioSocket/):
every message is 1 byte type, 2 bytes big-endian payload length, payload.
0x00 terminate, 0x01 UUID (16 raw bytes), 0x03 DTMF (1 ASCII byte), 0x10
audio (16-bit LE signed linear, 8 kHz mono; 0x11..0x18 are the same at 12,
16, 24, 32, 44.1, 48, 96 and 192 kHz), 0xff error. Asterisk 22's
AudioSocket() application forces the channel to slin (8 kHz), sends only
0x01, 0x03 and 0x10, and treats any message from us other than 0x10 or
0x00 as an error that hangs the channel up, so we send only those two.
It reads each header with a single read(), so every message goes out in
one sendall().
"""
import json
import logging
import queue
import threading
import time

from . import cloud_llm, conversation, farewell
from .voice_requests import APP_TYPE as VOICE_REQUESTS
from .voice_requests import Endpointing
from .call_media import CallMedia, KIND_HANGUP, TAIL_SECONDS, message
from .call_tools import CallTools, LookupInterrupted
from .call_turn import CallTurn

log = logging.getLogger("aipbx_ai.calls")


AUTOMATIC_GREETING_TIMEOUT = 3.0
# What the model is told when a turn brought no words (attempt["outcome"]).
LINE_NOTES = {
    "no_audio": "no sound at all came from the caller's line; they may not hear you either",
    "silence": "the caller said nothing",
    "quiet": "the caller's voice was too faint to understand",
    "unclear": "the caller spoke but it could not be understood (noise or a broken line)",
}


# ---- text ---------------------------------------------------------------


# ---- form fields ----------------------------------------------------------


# ---- sessions -------------------------------------------------------------


# ---- AudioSocket server ----------------------------------------------------


class VoiceRequestsCall(CallTools, CallTurn, CallMedia):
    """One voice_requests call on its AudioSocket connection.

    greeting -> listen -> (retry -> listen, up to `retries` times) -> reply of
    the matched intent, or not_understood -> result saved -> terminate (0x00).

    The reader thread keeps reading the caller's frames all the time; while we
    listen they go to session.listen_q. The frames that arrive while a prompt
    plays are dropped, unless the application allows barge-in: then the
    greeting and the retry prompt stop when the caller talks or presses a key,
    and listening goes on with what they said. Transcription runs in its own thread.
    The result is saved before the terminate message, so the dialplan finds it
    as soon as AudioSocket() returns.
    """

    def __init__(self, registry, conn, session, reader):
        self.registry = registry
        self.conn = conn
        self.session = session
        self.reader = reader
        self.config = session.config
        self.attempts = []
        self.missing_detectors = set()   # detectors chosen but not available: noted once per call
        self.audio = b""               # the caller's last utterance
        self.matched = None            # the intent dict
        self.reply_started = None      # monotonic
        self.speech_end = None         # monotonic arrival of the last loud frame of the last attempt
        self.history = []              # conversation mode: (role, text)
        self.summary = ""              # conversation mode: handed over with a transfer
        self.waiting_spoken = False
        self.automatic_waiting_pcm = b""
        self.automatic_waiting_key = ""
        self.endpointing = Endpointing()   # the end-of-turn silence learned from this caller
        self._turn = None              # the timings of the turn being answered, until its speech is made
        self._mu = threading.Lock()
        self._current = None           # the prompt being sent by the pump
        self._stop_pump = threading.Event()
        self._broken = threading.Event()
        self._pump_thread = threading.Thread(target=self._pump, name="call-pump", daemon=True)

    # ---- audio out ------------------------------------------------------


    # ---- audio in -------------------------------------------------------


    # ---- the call -------------------------------------------------------

    def run(self):
        self._pump_thread.start()
        try:
            return self._run()
        finally:
            self._stop_pump.set()
            self._pump_thread.join(1.0)

    def _run(self):
        session, cfg = self.session, self.config
        try:
            if cfg.get("conversation"):
                if cfg.get("realtime_cloud"):
                    from .realtime import RealtimeCall
                    RealtimeCall(self).run()           # a failure ends the call as "hangup": no cascade fallback
                    return self.session.result or "hangup"
                return self._converse()
            played, pre = self.play_interruptible(session.pcm)
            if not played:
                return self.save("hangup")
            for n in range(cfg["retries"] + 1):
                attempt = self.listen(pre)
                pre = None
                if attempt is None:
                    return self.save("hangup")
                self.attempts.append(attempt)
                session.transcript = attempt["transcript"] or session.transcript
                session.hear(attempt["transcript"])
                if attempt["intent"]:
                    self.matched = next(i for i in cfg["intents"] if i["id"] == attempt["intent"])
                    session.intent = self.matched["id"]
                    break
                if n < cfg["retries"]:
                    played, pre = self.play_interruptible(self.prompt("retry"))
                    if not played:
                        return self.save("hangup")
            if self.matched:
                result = "matched"
                pcm = self.prompt("reply:" + self.matched["id"])
            else:
                heard = any(a["speech_seconds"] for a in self.attempts)
                result = "not_understood" if heard else "silence"
                pcm = self.prompt("not_understood")
            return self._finish(pcm, result)
        except OSError:
            if not self.saved:
                self.save("hangup")
            return "hangup"

    def _finish(self, pcm, result):
        """The last words, the result saved, then "terminate": the dialplan goes on."""
        if not self.play(pcm, mark_reply=True):
            return self.save("hangup")
        self.save(result)
        if self.reader.gone.wait(TAIL_SECONDS):       # silence frames go on meanwhile
            return result
        self._stop_pump.set()
        self._pump_thread.join(1.0)
        self.conn.sendall(message(KIND_HANGUP))
        self.reader.gone.wait(1.0)
        return result

    # ---- conversation mode ----------------------------------------------


    def opening_audio(self, cfg):
        if not cfg.get("automatic_announcements"):
            return self.session.pcm
        result = queue.Queue(maxsize=1)
        expires = time.monotonic() + AUTOMATIC_GREETING_TIMEOUT

        def prepare():
            try:
                prompt = conversation.announcements_prompt(cfg)
                raw = cloud_llm.answer_text(cfg["llm_cloud"], prompt)
                greeting, waiting = conversation.parse_announcements(raw)
                name = cfg.get("caller_name", "").split()
                if name and name[0].casefold() not in greeting.casefold():
                    greeting = ""
                if time.monotonic() < expires:
                    result.put((self.speak(greeting) if greeting else b"", waiting))
            except Exception:  # an unavailable model leaves the prepared greeting in place
                result.put((b"", ""))

        threading.Thread(target=prepare, name="agent-greeting", daemon=True).start()
        try:
            pcm, waiting = result.get(timeout=AUTOMATIC_GREETING_TIMEOUT)
        except queue.Empty:
            return self.session.pcm
        if waiting:
            options = cfg.get("waiting_phrases", [])
            if waiting in options:
                self.automatic_waiting_key = f"waiting:{options.index(waiting)}"
            else:
                def prepare_waiting():
                    self.automatic_waiting_pcm = self.speak(waiting, record_turn=False, cfg=cfg)

                threading.Thread(target=prepare_waiting, name="agent-waiting-prompt", daemon=True).start()
        if pcm:
            log.info("call app=%d: automatic greeting ready", self.session.app)
        return pcm or self.session.pcm


    def _converse(self):
        """greeting -> (listen -> model step -> answer)* -> transfer, goodbye or the application's destination."""
        session, cfg = self.session, self.config
        cfg = {
            **cfg,
            "caller": session.caller,
            "did": session.did,
            "verified_customer": session.verified_customer,
        }
        if session.caller_name:
            cfg["caller_name"] = session.caller_name
        lang = session.lang or cfg.get("language") or "tr"
        cfg["call_language"] = lang
        if session.lang:
            cfg["language"], cfg["language_name"] = session.lang, session.lang_name
        self._active_cfg = cfg
        try:
            if session.context:
                self.history.append(("context", session.context))
            played, pre = self.play_interruptible(self.opening_audio(cfg))
            if not played:
                return self.save("hangup")
            quiet = 0
            for _ in range(cfg["max_turns"]):
                attempt = self.listen(pre)
                pre = None
                if attempt is None:
                    return self.save("hangup")
                self.attempts.append(attempt)
                text = attempt["transcript"]
                if attempt["dtmf"]:
                    text = (text + " " if text else "") + f"(pressed the key {attempt['dtmf']})"
                silent = not text
                if silent:
                    # Nothing usable: the model hears why (line note) and asks again in its own words;
                    # the fixed retry prompt stays the fallback, `retries` the hard limit.
                    quiet += 1
                    outcome = attempt.get("outcome") or "silence"
                    session.note(f"caller turn: {outcome} ({quiet} of {cfg['retries'] + 1})")
                    if quiet > cfg["retries"]:
                        break
                    self.history.append(("line", f"{LINE_NOTES[outcome]} (time {quiet}; "
                                                 f"the call leaves you after {cfg['retries'] + 1})"))
                else:
                    quiet = 0
                    session.transcript = text
                    session.hear(text)
                    last_said = next((t for r, t in reversed(self.history) if r == "assistant"), "")
                    self.history.append(("caller", text))
                    if cfg.get("end_on_goodbye") and farewell.caller_says_goodbye(text, lang, last_said):
                        session.note("the caller said goodbye: the call ends")
                        return self._finish(self.speak(cfg["goodbye"]) if cfg.get("goodbye") else b"", "ended")
                self.waiting_spoken = False
                started = time.monotonic()
                turn_lookup = [0.0]
                try:
                    step = conversation.next_step(
                        cfg, self.history, lang,
                        lambda tool, query, source=None, _acc=turn_lookup: self.lookup(tool, query, source, _acc=_acc),
                    )
                except LookupInterrupted as interrupted:
                    if interrupted.pre is None:
                        return self.save("hangup")
                    pre = interrupted.pre
                    continue
                except cloud_llm.CloudLlmError as e:
                    if silent:
                        log.info("call app=%d: model failed on a silent turn (%s): fixed retry prompt", session.app, e)
                        played, pre = self.play_interruptible(self.prompt("retry"))
                        if not played:
                            return self.save("hangup")
                        continue
                    log.warning("call app=%d: conversation model failed (%s): to the destination", session.app, e)
                    session.note(f"conversation model failed ({e}): the call went to its destination")
                    break
                attempt["step"] = step["action"]
                lookup_ms = turn_lookup[0] * 1000
                llm_ms = max(0.0, (time.monotonic() - started) * 1000 - lookup_ms)
                self._turn = {"stt_ms": attempt.get("stt_ms") or None, "llm_ms": llm_ms, "lookup_ms": lookup_ms or None,
                              "end_silence_ms": self.endpointing.frames * 20}
                log.info("call app=%d: conversation step %s in %d ms (lookups %d ms)", session.app, step["action"],
                         int(llm_ms), int(lookup_ms))
                if self.matched is not None and not (step["action"] == "transfer" and step.get("intent")):
                    if step["say"]:
                        self.history.append(("assistant", step["say"]))
                        pcm = self.speak(step["say"])
                    else:
                        pcm = b""
                        self._flush_silent_turn()
                    if cfg.get("builtin_agent") and step["say"] and not pcm:
                        break
                    return self._finish(pcm or self.prompt("reply:" + session.intent), "matched")
                if session.intent == "_end" and step["action"] != "end":
                    if step["say"]:
                        self.history.append(("assistant", step["say"]))
                        pcm = self.speak(step["say"])
                    else:
                        pcm = b""
                        self._flush_silent_turn()
                    if cfg.get("builtin_agent") and step["say"] and not pcm:
                        break
                    return self._finish(pcm, "ended")
                if step["action"] == "reply":
                    self.history.append(("assistant", step["say"]))
                    pcm = self.speak(step["say"])
                    if cfg.get("end_on_goodbye") and pcm and farewell.assistant_says_goodbye(step["say"], lang):
                        session.note("the agent said goodbye: the call ends")
                        return self._finish(pcm, "ended")
                    if silent and not pcm:
                        pcm = self.prompt("retry")
                    elif cfg.get("builtin_agent") and not pcm:
                        break
                    played, pre = self.play_interruptible(pcm)
                    if not played:
                        return self.save("hangup")
                    continue
                if step["say"]:
                    self.history.append(("assistant", step["say"]))
                if step["action"] == "end":
                    pcm = self.speak(step["say"]) if step["say"] else b""
                    if not step["say"]:
                        self._flush_silent_turn()
                    if cfg.get("builtin_agent") and step["say"] and not pcm:
                        break
                    return self._finish(pcm, "ended")
                self.summary = step.get("summary", "")
                if step["intent"]:
                    pcm = self.speak(step["say"]) if step["say"] else b""
                    if not step["say"]:
                        self._flush_silent_turn()
                    if cfg.get("builtin_agent") and step["say"] and not pcm:
                        break
                    self.matched = next(i for i in cfg["intents"] if i["id"] == step["intent"])
                    session.intent = self.matched["id"]
                    return self._finish(pcm or self.prompt("reply:" + session.intent), "matched")
                pcm = self.speak(step["say"]) if step["say"] else b""
                if not step["say"]:
                    self._flush_silent_turn()
                return self._finish(pcm or self.prompt("not_understood"), "not_understood")
            heard = any(a["speech_seconds"] for a in self.attempts)
            return self._finish(self.prompt("not_understood"), "not_understood" if heard else "silence")
        finally:
            self._active_cfg = None

    saved = False

    def save(self, result):
        """Stores the result (memory, JSON, WAV); returns result for finish()."""
        s, cfg = self.session, self.config
        intent = self.matched
        timings = {}
        if self.reply_started is not None and self.speech_end is not None:
            timings["speech_end_to_reply_ms"] = int((self.reply_started - self.speech_end) * 1000)
        record = {
            "uuid": s.uuid, "type": VOICE_REQUESTS, "app": s.app, "caller": s.caller, "caller_name": s.caller_name, "did": s.did,
            "uniqueid": s.uniqueid, "started": int(s.started), "ended": int(time.time()),
            "intent": intent["id"] if intent else None,
            "intent_name": (intent["name"] or intent["id"]) if intent else None,
            "score": 1.0 if (cfg.get("conversation") and intent) else (self.attempts[-1]["score"] if self.attempts else 0.0),
            "transcripts": [a["transcript"] for a in self.attempts],
            "dtmf": "".join(s.dtmf), "result": result, "audio": False,
            "attempts": self.attempts, "timings": timings,
        }
        if self.history:
            record["conversation"] = [{"role": r, "text": t} for r, t in self.history]
        if intent and intent.get("route_id"):
            record["route_id"] = intent["route_id"]
        if self.summary:
            record["summary"] = self.summary
        self.registry.results.save(record, self.audio)
        self.saved = True
        log.info("call app=%d caller=%s voice_requests result=%s intent=%s score=%.2f transcripts=%s %s",
                 s.app, s.caller or "-", result, record["intent"] or "none", record["score"],
                 json.dumps(record["transcripts"], ensure_ascii=False)[:300],
                 " ".join(f"{k}={v}" for k, v in timings.items()))
        return result
