"""Autonomous outbound call, IVR listener/DTMF navigator, and call transfer worker."""
from __future__ import annotations

import json
import logging
import queue
import re
import threading
import time
import uuid as uuid_mod
from typing import Any

from . import cloud_llm, cloud_stt
from .calls import CallSession
from .voice_requests import Listener, SAMPLE_RATE
from .wav import pcm16_from_wav, wav_bytes

log = logging.getLogger("aipbx_ai.outbound_nav")


def _clean_number(value: str) -> str:
    return re.sub(r"[^0-9+*#]", "", str(value or ""))[:24]


def _clean_ext(value: str) -> str:
    return re.sub(r"[^0-9]", "", str(value or ""))[:10]


def _clean_dtmf(value: str) -> str:
    return re.sub(r"[^0-9*#ABCDwW]", "", str(value or ""))[:32]


def parse_ivr_decision(raw: str) -> dict[str, str]:
    """Parses LLM JSON decision for an IVR step into {'action', 'digits'}."""
    match = re.search(r"\{.*\}", raw or "", re.S)
    data = None
    if match:
        try:
            data = json.loads(match.group(0))
        except ValueError:
            data = None
    if not isinstance(data, dict):
        return {"action": "wait", "digits": ""}
    action = str(data.get("action") or "wait").lower()
    if action not in ("dtmf", "connected", "wait"):
        action = "wait"
    digits = _clean_dtmf(str(data.get("digits") or ""))
    if action == "dtmf" and not digits:
        action = "wait"
    return {"action": action, "digits": digits}


def decide_ivr_action(
    app_cfg: dict[str, Any] | None,
    transcript: str,
    goal: str,
    target_dtmf: str,
    history: list[str],
    app_id: int = 0,
) -> dict[str, str]:
    """Decides whether to send DTMF, mark connected to a live person, or keep listening."""
    prompt = (
        "You are an AI telephone assistant navigating an automated company IVR or PBX greeting.\n"
        f"Goal: {goal or 'Reach the requested person or representative'}\n"
        f"Known direct sub-extension/DTMF (if any): {target_dtmf or '(none)'}\n"
        f"Steps taken so far: {json.dumps(history[-6:], ensure_ascii=False)}\n"
        f"What was just heard on the remote line: {json.dumps(transcript, ensure_ascii=False)}\n\n"
        "Decide the next action and reply with JSON only:\n"
        '- {"action": "dtmf", "digits": "<keys>"} when the announcement mentions a menu key or asks for an extension.\n'
        '- {"action": "connected"} when a live human representative or target person has answered.\n'
        '- {"action": "wait"} when still ringing, hold music, or unclear announcement.\n'
    )
    cfg = app_cfg or {}
    raw = ""
    try:
        if cfg.get("llm_cloud"):
            raw = cloud_llm.answer_text(cfg["llm_cloud"], prompt)
    except Exception as e:
        log.warning("IVR LLM decision fallback (%s): %s", type(e).__name__, e)
        raw = ""
    if raw:
        return parse_ivr_decision(raw)
    # Deterministic fallback when no LLM is attached
    low = transcript.casefold()
    if target_dtmf and any(k in low for k in ("dahili", "extension", "tuşla", "hoş", "welcome")):
        return {"action": "dtmf", "digits": target_dtmf}
    if any(k in low for k in ("efendim", "buyurun", "nasıl yardımcı", "hello", "speaking", "alô", "alo")):
        return {"action": "connected", "digits": ""}
    return {"action": "wait", "digits": ""}


class OutboundJob:
    def __init__(
        self,
        target_number: str,
        requester_ext: str,
        target_dtmf: str = "",
        goal: str = "",
        app_id: int = 0,
        caller_call_uuid: str = "",
        mode: str = "callback",
    ) -> None:
        self.job_id = str(uuid_mod.uuid4())
        self.target_number = target_number
        self.requester_ext = requester_ext
        self.target_dtmf = target_dtmf
        self.goal = goal
        self.app_id = app_id
        self.caller_call_uuid = caller_call_uuid
        self.caller_channel_id = ""
        self.mode = mode if mode in ("wait", "callback") else "callback"
        self.state = "dialing"
        self.detail = ""
        self.originated_id = ""
        self.outbound_channel_id = ""
        self.bridge_id = ""
        self.nav_uuid = ""
        self.callback_originated_id = ""
        self.bridge_channel_id = ""
        self.destroyed_channels: set[str] = set()
        self.answered_ev = threading.Event()
        self.bridge_ev = threading.Event()
        self.ended_ev = threading.Event()
        self.created = time.time()

    def to_dict(self) -> dict[str, Any]:
        return {
            "job_id": self.job_id,
            "target_number": self.target_number,
            "requester_ext": self.requester_ext,
            "target_dtmf": self.target_dtmf,
            "goal": self.goal,
            "mode": self.mode,
            "state": self.state,
            "detail": self.detail,
        }


class OutboundNavigator:
    """Places outbound calls via ARI, listens/navigates IVR menus via DTMF, and bridges to requester."""

    def __init__(self, ari_manager: Any, call_registry: Any) -> None:
        self.ari = ari_manager
        self.calls = call_registry
        self._lock = threading.Lock()
        self._jobs: dict[str, OutboundJob] = {}

    def get_job(self, job_id: str) -> dict[str, Any] | None:
        with self._lock:
            job = self._jobs.get(job_id)
            return job.to_dict() if job is not None else None

    def start_navigation(
        self,
        target_number: str,
        requester_ext: str,
        target_dtmf: str = "",
        goal: str = "",
        app_id: int = 0,
        caller_call_uuid: str = "",
        mode: str = "callback",
    ) -> dict[str, Any]:
        clean_num = _clean_number(target_number)
        clean_ext = _clean_ext(requester_ext)
        clean_dtmf = _clean_dtmf(target_dtmf)
        if len(clean_num) < 2:
            return {"ok": False, "error": "Invalid target_number"}
        if len(clean_ext) < 2:
            return {"ok": False, "error": "Invalid requester_ext"}

        job = OutboundJob(
            target_number=clean_num,
            requester_ext=clean_ext,
            target_dtmf=clean_dtmf,
            goal=str(goal or "").strip()[:200],
            app_id=max(0, int(app_id or 0)),
            caller_call_uuid=str(caller_call_uuid or "").strip(),
            mode=mode,
        )
        if job.mode == "wait" and job.caller_call_uuid and hasattr(self.ari, "get_caller_channel_id"):
            job.caller_channel_id = str(self.ari.get_caller_channel_id(job.caller_call_uuid) or "")
        with self._lock:
            self._jobs[job.job_id] = job
            # Keep memory bounded to last 200 jobs
            if len(self._jobs) > 200:
                oldest = next(iter(self._jobs))
                if oldest != job.job_id:
                    self._jobs.pop(oldest, None)

        threading.Thread(target=self._run_job, args=(job,), name=f"outnav-{job.job_id[:8]}", daemon=True).start()
        return {
            "ok": True,
            "job_id": job.job_id,
            "status": job.state,
            "message": f"Dialing {clean_num}" + (f" (ext/dtmf {clean_dtmf})" if clean_dtmf else "") + f" for {clean_ext} ({job.mode}).",
        }

    def on_ari_event(self, event: dict[str, Any]) -> None:
        etype = str(event.get("type") or "")
        ch = event.get("channel") or {}
        ch_id = str(ch.get("id") or "")
        args = [str(a) for a in (event.get("args") or [])]

        if etype == "StasisStart" and len(args) >= 2:
            kind, job_id = args[0], args[1]
            with self._lock:
                job = self._jobs.get(job_id)
                if job is None:
                    return
                if kind == "outbound_nav":
                    job.outbound_channel_id = ch_id
                    job.state = "answered"
                    job.answered_ev.set()
                elif kind == "outbound_bridge":
                    job.bridge_channel_id = ch_id
                    job.bridge_ev.set()
            return

        if etype == "ChannelStateChange" and ch_id:
            ch_state = str(ch.get("state") or "")
            if ch_state == "Up":
                with self._lock:
                    for j in self._jobs.values():
                        if ch_id in (j.originated_id, j.outbound_channel_id) and not j.answered_ev.is_set():
                            j.outbound_channel_id = j.outbound_channel_id or ch_id
                            j.state = "answered"
                            j.answered_ev.set()
                            break
            return

        if etype == "ChannelDestroyed" and ch_id:
            cause_txt = str(event.get("cause_txt") or event.get("cause") or "disconnected")
            with self._lock:
                for j in self._jobs.values():
                    if ch_id in (j.originated_id, j.outbound_channel_id):
                        j.destroyed_channels.add(ch_id)
                        if not j.answered_ev.is_set():
                            j.state = "failed"
                            j.detail = f"Call not answered ({cause_txt})"
                            j.answered_ev.set()
                        j.ended_ev.set()
                    elif ch_id in (j.callback_originated_id, j.bridge_channel_id, j.caller_channel_id):
                        j.destroyed_channels.add(ch_id)
                        if not j.answered_ev.is_set():
                            j.state = "failed"
                            j.detail = f"Caller disconnected ({cause_txt})"
                            j.answered_ev.set()
                        j.ended_ev.set()

    def _transcribe(self, cfg: dict[str, Any], pcm: bytes) -> str:
        if not pcm:
            return ""
        try:
            if str(cfg.get("stt_model") or "").startswith("cloud:") and cfg.get("stt_cloud"):
                pcm16k, _ = pcm16_from_wav(wav_bytes(pcm, SAMPLE_RATE))
                return str(cloud_stt.transcribe(cfg["stt_cloud"], wav_bytes(pcm16k, 16000)) or "").strip()
        except Exception as e:
            log.debug("outbound transcribe failed: %s", e)
        return ""

    def _wait_audio_turn(self, q: queue.Queue[Any], listen_seconds: float, stop_ev: threading.Event) -> bytes:
        listener = Listener(listen_seconds)
        started = time.monotonic()
        wall_limit = started + listen_seconds + 1.5
        reason = None
        while reason is None and not stop_ev.is_set():
            try:
                kind, at, data = q.get(timeout=0.05)
            except queue.Empty:
                if time.monotonic() > wall_limit:
                    break
                continue
            if kind == "audio":
                reason = listener.feed(data, at)
        return listener.utterance() if listener.heard else b""

    def _run_job(self, job: OutboundJob) -> None:
        nav_session = None
        try:
            orig = self.ari.client.originate(
                endpoint=f"Local/{job.target_number}@from-internal-pbx/n",
                app=self.ari.app_name,
                app_args=f"outbound_nav,{job.job_id}",
                caller_id=job.requester_ext,
                timeout=35,
            )
            with self._lock:
                job.originated_id = str(orig.get("id") or "")
            job.answered_ev.wait(timeout=38.0)
            if job.state != "answered" or not job.outbound_channel_id:
                job.state = "failed"
                if not job.detail:
                    job.detail = "No answer / timeout"
                log.info("outbound job %s failed before answer: %s", job.job_id, job.detail)
                return

            bridge = self.ari.client.create_bridge("mixing")
            job.bridge_id = str(bridge.get("id") or "")
            if not job.bridge_id:
                job.state = "failed"
                job.detail = "Could not create ARI bridge"
                return
            self.ari.client.add_channel(job.bridge_id, job.outbound_channel_id)

            app_cfg, _ = self.calls.apps.get(job.app_id) if job.app_id > 0 else (None, None)
            if app_cfg is None:
                # Pick first active app config with STT/LLM if app_id wasn't given
                for item in self.calls.apps.list():
                    c, _ = self.calls.apps.get(int(item["id"]))
                    if c:
                        app_cfg = c
                        break

            # Attach listen-only AudioSocket to hear remote greeting/IVR
            nav_session = CallSession(job.app_id, "listen", job.target_number, job.requester_ext, job.job_id, kind="listen")
            nav_session.listen_q = queue.Queue()
            job.nav_uuid = nav_session.uuid
            self.calls.register_listen_session(nav_session)
            self.ari.register_ext_channel(job.nav_uuid)
            self.ari.client.external_media(
                app=self.ari.app_name,
                external_host=f"{self.ari.audiosocket_host}:{self.ari.audiosocket_port}",
                fmt="slin",
                encapsulation="audiosocket",
                transport="tcp",
                channel_id=job.nav_uuid,
                data=job.nav_uuid,
            )
            self.ari.client.add_channel(job.bridge_id, job.nav_uuid)
            job.state = "navigating"

            if job.target_dtmf and not job.goal:
                # Direct sub-extension mode (e.g. AFM Mehmet Bey -> ext 1000): wait briefly for remote PBX greeting onset then dial
                self._wait_audio_turn(nav_session.listen_q, 2.2, job.ended_ev)
                if not job.ended_ev.is_set():
                    self.ari.client.send_dtmf(job.outbound_channel_id, job.target_dtmf)
                    job.detail = f"Dialed sub-extension {job.target_dtmf}"
                    time.sleep(1.2)
            elif job.goal or job.target_dtmf:
                history: list[str] = []
                silent_turns = 0
                sent_initial_dtmf = False
                for _step in range(6):
                    if job.ended_ev.is_set():
                        break
                    pcm = self._wait_audio_turn(nav_session.listen_q, 10.0, job.ended_ev)
                    if job.ended_ev.is_set():
                        break
                    if not pcm:
                        silent_turns += 1
                        if job.target_dtmf and not sent_initial_dtmf:
                            self.ari.client.send_dtmf(job.outbound_channel_id, job.target_dtmf)
                            history.append(f"dtmf:{job.target_dtmf}")
                            sent_initial_dtmf = True
                            continue
                        if silent_turns >= 2:
                            break
                        continue
                    silent_turns = 0
                    transcript = self._transcribe(app_cfg or {}, pcm)
                    if not transcript:
                        continue
                    history.append(f"remote:{transcript}")
                    decision = decide_ivr_action(
                        app_cfg, transcript, job.goal,
                        "" if sent_initial_dtmf else job.target_dtmf,
                        history, app_id=job.app_id,
                    )
                    if decision["action"] == "dtmf" and decision["digits"]:
                        self.ari.client.send_dtmf(job.outbound_channel_id, decision["digits"])
                        history.append(f"dtmf:{decision['digits']}")
                        if decision["digits"] == job.target_dtmf:
                            sent_initial_dtmf = True
                        time.sleep(0.8)
                    elif decision["action"] == "connected":
                        job.detail = "Representative connected"
                        break

            # Tear down listener leg before bridging requester
            self._detach_nav_listener(job, nav_session)
            nav_session = None
            if job.ended_ev.is_set():
                job.state = "failed"
                job.detail = job.detail or "Remote party disconnected before bridge"
                return

            job.state = "bridging"
            if job.mode == "wait" and job.caller_call_uuid and self.ari.rebridge_caller(job.caller_call_uuid, job.bridge_id):
                job.state = "bridged"
                job.detail = job.detail or "Hot-bridged waiting caller"
            else:
                if job.mode == "wait":
                    with self._lock:
                        job.caller_channel_id = ""
                orig_cb = self.ari.client.originate(
                    endpoint=f"Local/{job.requester_ext}@from-internal-pbx/n",
                    app=self.ari.app_name,
                    app_args=f"outbound_bridge,{job.job_id}",
                    caller_id=f"AI Aktarim <{job.target_number}>",
                    timeout=30,
                )
                with self._lock:
                    job.callback_originated_id = str(orig_cb.get("id") or "")
                job.bridge_ev.wait(timeout=32.0)
                if job.bridge_channel_id and not job.ended_ev.is_set():
                    self.ari.client.add_channel(job.bridge_id, job.bridge_channel_id)
                    job.state = "bridged"
                    job.detail = job.detail or f"Callback bridged to {job.requester_ext}"
                else:
                    job.state = "failed"
                    job.detail = "Requester did not answer callback"

            if job.state == "bridged":
                job.ended_ev.wait(timeout=7200.0)
        except Exception as e:
            log.warning("outbound job %s error: %s", job.job_id, e)
            job.state = "failed"
            job.detail = str(e)[:150]
        finally:
            if nav_session is not None:
                self._detach_nav_listener(job, nav_session)
            if job.bridge_id:
                try:
                    self.ari.client.destroy_bridge(job.bridge_id)
                except Exception:
                    pass
            with self._lock:
                hangup_ids = [
                    cid for cid in dict.fromkeys([
                        job.originated_id,
                        job.outbound_channel_id,
                        job.callback_originated_id,
                        job.bridge_channel_id,
                        job.caller_channel_id,
                    ])
                    if cid and cid not in job.destroyed_channels
                ]
            for cid in hangup_ids:
                try:
                    self.ari.client.hangup(cid)
                except Exception:
                    pass

    def _detach_nav_listener(self, job: OutboundJob, nav_session: Any) -> None:
        try:
            self.calls.finish(nav_session, "done")
        except Exception:
            pass
        if job.nav_uuid:
            self.ari.unregister_ext_channel(job.nav_uuid)
            try:
                self.ari.client.remove_channel(job.bridge_id, job.nav_uuid)
            except Exception:
                pass
            try:
                self.ari.client.hangup(job.nav_uuid)
            except Exception:
                pass
