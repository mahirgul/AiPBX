"""What the agent does between two sentences: the lookups (knowledge base, saved queries, HTTP, PBX tools), the
waiting sound while one runs, and collecting keys. Above the turn (it plays a waiting phrase the caller may talk over).
"""
import logging
import json
import math
import queue
import random
import re
import struct
import threading
import time
import urllib.request
from . import conversation, secret_refs
from .call_media import SAMPLE_RATE
from .call_text import fetch_values, lookup_url


log = logging.getLogger("aipbx_ai.calls")


LOOKUP_PROMPT_DELAY = 0.7     # let fast lookups finish without playing a waiting phrase


class LookupInterrupted(Exception):
    def __init__(self, pre):
        self.pre = pre


_THINKING = None


def thinking_sound(seconds=2.4, seed=7):
    """Quiet keyboard typing (8 kHz 16-bit PCM) for a lookup without a waiting phrase (the idea of LiveKit's
    background "thinking" audio). Made once: short decaying clicks at uneven intervals, with a pause or two."""
    global _THINKING
    if _THINKING is None:
        rng = random.Random(seed)
        samples = [0] * int(seconds * SAMPLE_RATE)
        at = int(0.15 * SAMPLE_RATE)
        while at < len(samples) - 80:
            peak = rng.uniform(900, 1700)
            for i in range(48):                     # 6 ms: a key going down
                samples[at + i] += int(rng.uniform(-1, 1) * peak * math.exp(-i / 9))
            at += int(rng.uniform(0.08, 0.22) * SAMPLE_RATE) + (int(0.45 * SAMPLE_RATE) if rng.random() < 0.08 else 0)
        _THINKING = struct.pack(f"<{len(samples)}h", *(max(-32768, min(32767, v)) for v in samples))
    return _THINKING


class CallTools:
    """CallTools (a part of VoiceRequestsCall; its methods use the call's own attributes)."""

    def lookup(self, tool, query, source=None, _acc=None):
        """One of the application's lookups, as the text the model reads; ValueError on failure."""
        if tool == "collect_dtmf" or self.waiting_spoken:
            t0 = time.monotonic()
            try:
                return self.resolve_lookup(tool, query, source)
            finally:
                if _acc is not None:
                    _acc[0] += time.monotonic() - t0
        phrases = self.config.get("waiting_phrases", [])
        s = self.session
        clips = [self.registry.apps.cached(s.app, s.version, f"waiting:{i}") for i in range(len(phrases))]
        clips = [pcm for pcm in clips if pcm]
        preferred = self.automatic_waiting_pcm
        if self.automatic_waiting_key:
            preferred = self.registry.apps.cached(s.app, s.version, self.automatic_waiting_key) or preferred
        if preferred:
            clips = [preferred]
        used_thinking = False
        if not clips:
            clips = [thinking_sound()]     # no waiting phrase: a quiet typing sound tells the caller we are on it
            used_thinking = True

        done = queue.Queue(maxsize=1)
        done_ev = threading.Event()

        def run():
            t0 = time.monotonic()
            try:
                done.put((self.resolve_lookup(tool, query, source), None))
            except Exception as exc:
                done.put((None, exc))
            finally:
                if _acc is not None:
                    _acc[0] += time.monotonic() - t0
                done_ev.set()

        threading.Thread(target=run, name="agent-lookup", daemon=True).start()
        try:
            result, error = done.get(timeout=LOOKUP_PROMPT_DELAY)
        except queue.Empty:
            self.waiting_spoken = True
            log.info("call app=%d: playing a lookup waiting phrase", s.app)
            pcm_wait = random.choice(clips)
            played, pre = self.play_interruptible(pcm_wait, stop_when=done_ev) if used_thinking else self.play_interruptible(pcm_wait)
            if not played or pre is not None:
                raise LookupInterrupted(pre)
            result, error = done.get()
        if error is not None:
            raise error
        return result

    def resolve_lookup(self, tool, query, source=None):
        s = self.session
        if tool == conversation.KNOWLEDGE_TOOL and self.config.get("knowledge"):
            return conversation.knowledge_lookup(self.config, s.app, query, source, secret_refs.knowledge)
        if tool == conversation.DB_TOOL and self.config.get("db_queries"):
            result = secret_refs.db_value(s.app, query, s.verified_customer, {
                "caller_id": s.caller, "caller_name": s.caller_name, "did": s.did,
                "uniqueid": s.uniqueid, "linkedid": s.linkedid, "lang": self.config.get("language") or "",
                "customer_id": s.verified_customer,
            })
            if self.config.get("builtin_agent") and json.loads(result)["status"] == "unavailable":
                raise ValueError("database lookup unavailable")
            return result
        if tool == "collect_dtmf":
            args = {}
            if isinstance(query, dict):
                args = query
            elif isinstance(query, str) and query.strip().startswith("{"):
                try:
                    args = json.loads(query)
                except ValueError:
                    args = {}
            try:
                max_digits = max(1, min(32, int(args.get("max_digits") or 11)))
            except (ValueError, TypeError):
                max_digits = 11
            try:
                timeout_sec = max(2, min(20, int(args.get("timeout_sec") or 8)))
            except (ValueError, TypeError):
                timeout_sec = 8
            terminator = str(args.get("terminator") if args.get("terminator") is not None else "#")[:1]
            collected = self._collect_dtmf_live(max_digits=max_digits, timeout_sec=timeout_sec, terminator=terminator)
            return json.dumps({"ok": True, "digits": collected}, ensure_ascii=False)
        if tool == "send_dtmf" and getattr(self.registry, "ari_manager", None) is not None:
            args = {}
            if isinstance(query, dict):
                args = query
            elif isinstance(query, str) and query.strip().startswith("{"):
                try:
                    args = json.loads(query)
                except ValueError:
                    args = {}
            else:
                args = {"digits": str(query or "")}
            digits = re.sub(r"[^0-9*#ABCDwW]", "", str(args.get("digits") or ""))
            try:
                pause_ms = max(50, min(500, int(args.get("pause_ms") or 100)))
            except (ValueError, TypeError):
                pause_ms = 100
            if not digits:
                return json.dumps({"ok": False, "text": "Invalid DTMF digits."})
            try:
                if self.registry.ari_manager.send_dtmf_for_call(s.uuid, digits, between=pause_ms):
                    return json.dumps({"ok": True, "text": f"Sent DTMF digits: {digits}"})
            except Exception as e:
                return json.dumps({"ok": False, "text": f"Could not send DTMF: {e}"})
            return json.dumps({"ok": False, "text": "Active call channel not found for DTMF."})
        pbx_map = {t["name"]: t for t in (self.config.get("pbx_tools") or []) if isinstance(t, dict) and t.get("name")}
        if tool in pbx_map:
            from .pbx_tools import PbxToolsClient
            args = {}
            if isinstance(query, dict):
                args = query
            elif isinstance(query, str) and query.strip().startswith("{"):
                try:
                    args = json.loads(query)
                except ValueError:
                    args = {}
            if not args and isinstance(query, str) and query.strip():
                props = list(((pbx_map[tool].get("parameters") or {}).get("properties") or {}).keys())
                key_name = props[0] if props else "query"
                args = {key_name: query.strip()}
            client = PbxToolsClient(key=str(self.config.get("pbx_tools_key") or ""))
            res = client.execute(tool, args, caller={
                "app_id": s.app,
                "call_uuid": s.uuid,
                "caller": s.caller,
                "caller_name": s.caller_name,
                "did": s.did,
                "verified_customer": s.verified_customer,
            })
            action = res.get("action") or {}
            if res.get("ok") and isinstance(action, dict):
                atype = action.get("type")
                if atype == "transfer":
                    target = str(action.get("target") or "")
                    if target:
                        intent = next((i for i in (self.config.get("intents") or []) if i.get("id") == target), None)
                        if intent is None:
                            intent = {"id": target, "name": target}
                            if action.get("route_id"):
                                intent["route_id"] = str(action["route_id"])
                        self.matched = intent
                        s.intent = target
                elif atype in ("hangup", "end"):
                    s.intent = "_end"
            return str(res.get("text") or json.dumps(res, ensure_ascii=False))
        t = next(t for t in self.config["tools"] if t["name"] == tool)
        url = lookup_url(t["url"], s.caller, s.did, s.app)
        url += ("&" if "?" in url else "?") + urllib.parse.urlencode({"q": query})
        return json.dumps(fetch_values(url), ensure_ascii=False)

    def _collect_dtmf_live(self, max_digits=11, timeout_sec=8, terminator="#"):
        """Collects DTMF keypad input from the caller over listen_q."""
        s = self.session
        q = queue.Queue()
        digits = []
        s.listen_q = q
        deadline = time.monotonic() + float(timeout_sec)
        try:
            while len(digits) < max_digits and not self.gone():
                rem = deadline - time.monotonic()
                if rem <= 0:
                    break
                try:
                    kind, _at, data = q.get(timeout=min(0.2, rem))
                except queue.Empty:
                    continue
                if kind == "dtmf":
                    ch = str(data)
                    if terminator and ch == terminator:
                        break
                    digits.append(ch)
                    # Give caller up to 3s per subsequent digit
                    deadline = max(deadline, time.monotonic() + 3.0)
        finally:
            s.listen_q = None
        return "".join(digits)
