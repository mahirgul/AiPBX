"""One turn of the caller: listening for them (also while a prompt plays, for barge-in), turning what they said
into text, and speaking an answer. Above the audio (call_media), below the lookups and the conversation.
"""
import logging
import queue
import threading
import time
from . import cloud_llm, langs, secret_refs
from .conversation import speakable
from .intents import match as match_intent
from .voice_requests import CALIBRATION_FRAMES, Listener, NO_SPEECH_SECONDS, PRE_ROLL_FRAMES
from .call_media import FRAME_SECONDS, SAMPLE_RATE, SILENCE_FRAME


log = logging.getLogger("aipbx_ai.calls")


BARGE_FRAMES = 10            # 200 ms of speech over a prompt stop it (barge_in)


NO_FRAMES_GAP = 0.2          # listening: after this long without inbound frames, count silence


class CallTurn:
    """CallTurn (a part of VoiceRequestsCall; its methods use the call's own attributes)."""

    def play_interruptible(self, pcm, stop_when=None):
        """Plays a prompt the caller may talk over (barge_in).

        (False, None): the caller hung up. (True, None): it played out.
        (True, pre): the caller talked or pressed a key; the prompt stopped and
        `pre` is what listen() goes on with: the queue, a Listener fed with the
        audio before and since the speech, the digits, a stop reason or None.
        """
        if not self.config.get("barge_in") or not pcm:
            return self.play(pcm, stop_when=stop_when), None
        session = self.session
        q = queue.Queue()
        # only its speech detection is used here; without its own setting a faint line profile does not
        # carry over (line noise would stop prompts)
        cfg = self.config
        probe = Listener(3600, profile=cfg.get("barge_profile") or ("noisy" if cfg.get("line_profile") == "noisy" else "normal"),
                         start_frames=BARGE_FRAMES, vad=self.detectors()[0])
        cur = {"pcm": pcm, "pos": 0, "sent": threading.Event(), "started": None, "ends": None}
        handed_over = False
        session.listen_q = q
        with self._mu:
            self._current = cur
        try:
            while True:
                if self.gone():
                    return False, None
                if stop_when is not None and stop_when.is_set():
                    with self._mu:
                        if self._current is cur:
                            self._current = None
                    return not self.gone(), None
                if cur["ends"] is not None and time.monotonic() >= cur["ends"]:
                    return True, None
                try:
                    kind, at, data = q.get(timeout=0.02)
                except queue.Empty:
                    continue
                if kind == "audio":
                    probe.feed(data, at)
                    onset = None if probe.start is None else probe.start + PRE_ROLL_FRAMES
                    if onset is None or probe.last_loud is None or probe.last_loud - onset < BARGE_FRAMES:
                        continue
                with self._mu:
                    if self._current is cur:
                        self._current = None
                started = time.monotonic()
                listener = self.new_listener()
                reason = None
                if kind == "dtmf":
                    digits, reason = [data], "dtmf"
                else:
                    digits = []
                    # The quiet before the speech first: it sets the noise floor, as at the start of listening.
                    seed = probe.frames[max(0, probe.start - CALIBRATION_FRAMES):]
                    started -= len(seed) * FRAME_SECONDS
                    reason = listener.feed(b"".join(seed), at)
                log.info("call app=%d: barge-in (%s)", session.app, kind)
                handed_over = True
                return True, (q, listener, started, digits, reason)
        finally:
            if not handed_over:
                session.listen_q = None

    def prompt(self, key):
        """The prompt's audio; b"" when it cannot be made (the call goes on without it)."""
        s = self.session
        try:
            return self.registry.apps.prompt(s.app, s.version, self.config, key)
        except Exception as e:  # noqa: BLE001
            log.warning("call app=%d: prompt %s failed (%s)", s.app, key, type(e).__name__)
            s.note(f"prompt {key} failed: {type(e).__name__}")
            return b""

    def detectors(self):
        """(a Silero stream or None, the Smart Turn backend or None): what the agent asked for and the models
        that run. The call log says once when a chosen one is missing; the call goes on without it."""
        cfg = self.config
        ready = getattr(self.registry.manager, "ready_backend", None)
        found = {}
        for setting, wanted, kind, title in (("speech_detection", "silero", "vad", "Silero VAD"),
                                             ("end_of_turn", "smart", "turn", "Smart Turn")):
            if cfg.get(setting) != wanted:
                continue
            backend = ready(kind) if ready else None
            if backend is None and kind not in self.missing_detectors:
                self.missing_detectors.add(kind)
                self.session.note(f"{title} is chosen but not installed or not running: the standard detection is used")
            found[kind] = backend
        vad = found.get("vad")
        return (vad.new_stream() if vad else None), found.get("turn")

    def new_listener(self):
        cfg = self.config
        vad, turn = self.detectors()
        # A conversation learns this caller's pauses over the whole call (one Endpointing per call).
        return Listener(cfg["listen_seconds"], cfg.get("silence_seconds", NO_SPEECH_SECONDS), cfg.get("line_profile", "normal"),
                        vad=vad, turn=turn, endpointing=self.endpointing if cfg.get("conversation") else None)

    def listen(self, pre=None):
        """One attempt: the attempt dict, or None when the caller hung up.

        `pre`: what play_interruptible() heard over a prompt, to go on with."""
        session, reader, cfg = self.session, self.reader, self.config
        if pre is not None:
            q, listener, started, digits, reason = pre
        else:
            q, listener, started, digits, reason = queue.Queue(), self.new_listener(), time.monotonic(), [], None
        # Collect the caller's audio before the Deepgram connection is made: opening it
        # may take up to its connect timeout, and audio not queued by then was lost.
        session.listen_q = q
        dg_stream = None
        sc = cfg.get("stt_cloud") or {}
        if sc.get("provider") == "deepgram":
            try:
                resolved_sc = secret_refs.resolve(sc) if "secret_ref" in sc else sc
            except Exception:  # noqa: BLE001
                resolved_sc = {}
            if resolved_sc.get("api_key"):
                try:
                    from .deepgram_stream import DeepgramStream
                    dg_stream = DeepgramStream(
                        resolved_sc["api_key"],
                        lang=self.session.lang or resolved_sc.get("lang") or cfg.get("language") or "tr",
                        sample_rate=SAMPLE_RATE,
                        keyterms=cfg.get("stt_keyterms") or (),
                        **({"model": resolved_sc["model"]} if resolved_sc.get("model") else {}),
                    )
                    dg_stream.connect()
                    if listener.frames:
                        dg_stream.send_audio(b"".join(listener.frames))
                except Exception as e:  # noqa: BLE001 - fall back cleanly to REST STT
                    log.debug("call app=%d: deepgram stream unavailable (%s)", session.app, type(e).__name__)
                    if dg_stream is not None:
                        dg_stream.close()
                    dg_stream = None
        wall_limit = started + cfg["listen_seconds"] + 2.0   # Asterisk stopped sending audio
        signal = False
        try:
            while reason is None:
                if self.gone():
                    return None
                try:
                    kind, at, data = q.get(timeout=0.05)
                except queue.Empty:
                    now = time.monotonic()
                    if now > wall_limit:
                        reason = "timeout" if listener.heard else "silence"
                        continue
                    # No frames from Asterisk (a channel that sends no audio,
                    # e.g. a Local channel in Wait()): the missing time is silence.
                    missing = int((now - started - NO_FRAMES_GAP) / FRAME_SECONDS) - len(listener.frames)
                    if missing > 0:
                        reason = listener.feed(SILENCE_FRAME * missing, now)
                    continue
                if kind == "dtmf":
                    digits.append(data)
                    reason = "dtmf"
                else:
                    signal = True                # frames from the line, not our silence fill
                    if dg_stream is not None:
                        dg_stream.send_audio(data)
                    reason = listener.feed(data, at)
        finally:
            session.listen_q = None
            if (reason is None or not listener.heard) and dg_stream is not None:
                dg_stream.close()
                dg_stream = None
        ended = time.monotonic()
        for line in listener.turn_notes:
            session.note(line)
        listener.turn_notes.clear()
        attempt = {"reason": reason, "transcript": "", "intent": None, "score": 0.0, "second": 0.0,
                   "scores": {}, "dtmf": "".join(digits), "listened_seconds": round(ended - started, 2),
                   "speech_seconds": 0.0, "stt_ms": 0}
        if listener.heard:
            pcm = listener.utterance()
            self.audio = pcm
            self.speech_end = listener.last_loud_time
            attempt["speech_seconds"] = round(len(pcm) / 2 / SAMPLE_RATE, 2)
            text = ""
            if dg_stream is not None:
                t0 = time.monotonic()
                text = dg_stream.finish(timeout=1.5)
                if text:
                    attempt["stt_ms"] = int((time.monotonic() - t0) * 1000)
            if not text:
                text, attempt["stt_ms"] = self.transcribe(pcm)
            attempt["transcript"] = text
            if text and not cfg.get("conversation"):
                m = self.understand(text, cfg)
                attempt.update(intent=m["intent"], score=m["score"], second=m["second"], scores=m["scores"],
                               method=m["method"])
        else:
            self.speech_end = None
        if not attempt["transcript"] and not attempt["dtmf"]:
            attempt["outcome"] = ("unclear" if listener.heard else
                                  "quiet" if listener.faint() else
                                  "silence" if signal or pre is not None else "no_audio")
        return attempt

    def understand(self, text, cfg):
        """The request in what the caller said (intents.match result shape)."""
        if cfg.get("understand") == "cloud" and cfg.get("llm_cloud"):
            started = time.monotonic()
            try:
                c = cloud_llm.classify(cfg["llm_cloud"], text, cfg["intents"])
                log.info("call app=%d: cloud model %s answered in %d ms", self.session.app,
                         cfg["llm_cloud"]["provider"], int((time.monotonic() - started) * 1000))
                sure = c["intent"] is not None and c["confidence"] >= cfg["threshold"]
                return {"intent": c["intent"] if sure else None, "score": c["confidence"], "second": 0.0,
                        "scores": {c["intent"]: c["confidence"]} if c["intent"] else {}, "method": "cloud"}
            except Exception as e:  # noqa: BLE001 - never lose the call: fall back to keyword matching
                log.warning("call app=%d: cloud model failed (%s), keyword matching", self.session.app,
                            type(e).__name__ + ": " + str(e)[:80])
                self.session.note("cloud model failed, keyword matching: " + type(e).__name__ + ": " + str(e))
        return match_intent(text, cfg["intents"], cfg["threshold"])

    def stt_config(self):
        """The cloud speech-to-text settings with the call's language."""
        s, cfg = self.session, dict(self.config["stt_cloud"])
        if s.lang:
            cfg.update(lang=s.lang, locale=s.lang_locale or langs.locale(s.lang), lang_name=s.lang_name or langs.name(s.lang))
        if self.config.get("stt_keyterms"):
            cfg["keyterms"] = list(self.config["stt_keyterms"])
        return cfg

    def transcribe(self, pcm):
        """(text, ms); runs in a thread of its own, the reader keeps reading meanwhile."""
        from .wav import wav_bytes

        box = {}

        def run():
            try:
                from . import cloud_stt
                from .wav import pcm16_from_wav
                pcm16k, _ = pcm16_from_wav(wav_bytes(pcm, SAMPLE_RATE))   # 16 kHz: what every provider takes
                box["text"] = cloud_stt.transcribe(self.stt_config(), wav_bytes(pcm16k, 16000))
            except Exception as e:  # noqa: BLE001
                box["error"] = type(e).__name__
        started = time.monotonic()
        deadline = started + 30.0
        t = threading.Thread(target=run, name="call-stt", daemon=True)
        t.start()
        while t.is_alive() and time.monotonic() < deadline:
            if self.gone():
                break
            t.join(min(0.1, max(0.0, deadline - time.monotonic())))
        ms = int((time.monotonic() - started) * 1000)
        if self.gone() and t.is_alive():
            return "", ms
        if "error" in box or t.is_alive():
            log.warning("call app=%d: transcription failed (%s)", self.session.app, box.get("error", "timeout"))
            self.session.note("transcription failed: " + str(box.get("error", "timeout")))
        text = box.get("text") or ""
        return (text.strip() if isinstance(text, str) else ""), ms

    def speak(self, text, *, record_turn=True, cfg=None):
        """A text of the conversation in the application's voice; b"" when it cannot be made.
        The first speech of a turn closes its timings (synthesis, and the caller's wait for the first sound)."""
        started = time.monotonic()
        pcm = b""
        use_cfg = cfg or getattr(self, "_active_cfg", None) or self.config
        try:
            pcm = self.registry.apps.speech(self.session.app, use_cfg, speakable(text))
            return pcm
        except Exception as e:  # noqa: BLE001
            log.warning("call app=%d: speech failed (%s)", self.session.app, type(e).__name__)
            self.session.note("speech synthesis failed: " + type(e).__name__)
            return b""
        finally:
            if record_turn:
                turn, self._turn = self._turn, None
                if turn is not None and pcm:
                    now = time.monotonic()
                    turn["tts_ms"] = (now - started) * 1000
                    if self.speech_end is not None:
                        turn["first_audio_ms"] = (now - self.speech_end) * 1000
                    self.session.turn(turn)

    def _flush_silent_turn(self):
        turn, self._turn = self._turn, None
        if turn is not None:
            turn["tts_ms"] = None
            turn["first_audio_ms"] = None
            self.session.turn(turn)
