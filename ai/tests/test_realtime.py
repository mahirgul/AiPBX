import base64
import hashlib
import json
import queue
import socket
import struct
import time
import threading
import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from aipbx_ai.call_session import CallSession
from aipbx_ai.calls import VoiceRequestsCall

from aipbx_ai.realtime import (
    SILENCE_NUDGE_SECONDS,
    RealtimeCall,
    RealtimeSocket,
    _Downsampler24to8,
    _Upsampler8to16,
    _resolve_ws_endpoint,
    pcm16_to_ulaw,
    resample_pcm16,
    ulaw_to_pcm16,
)

_WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def _ws_server_frame(payload: bytes, opcode: int = 0x1) -> bytes:
    n = len(payload)
    if n < 126:
        hdr = bytes([0x80 | opcode, n])
    else:
        hdr = bytes([0x80 | opcode, 126]) + struct.pack("!H", n)
    return hdr + payload


def _ws_read_client_frame(conn: socket.socket) -> dict | None:
    hdr = conn.recv(2)
    if len(hdr) < 2:
        return None
    opcode = hdr[0] & 0x0F
    if opcode == 0x8:
        return None
    length = hdr[1] & 0x7F
    if length == 126:
        length = struct.unpack("!H", conn.recv(2))[0]
    mask = conn.recv(4)
    buf = bytearray()
    while len(buf) < length:
        buf.extend(conn.recv(length - len(buf)))
    data = bytes(b ^ mask[i & 3] for i, b in enumerate(buf))
    return json.loads(data.decode("utf-8")) if opcode == 0x1 else {}


class _FakeCall:
    def __init__(self, ws_url: str) -> None:
        self.config = {
            "greeting": "Merhaba",
            "instructions": "Help the caller.",
            "stt_lang": "tr",
            "intents": [{"id": "sales", "name": "Sales"}],
            "realtime_cloud": {
                "provider": "openai",
                "api_key": "sk-test",
                "model": "gpt-4o-realtime-preview",
                "voice": "alloy",
                "lang": "tr",
                "ws_url": ws_url,
            },
        }
        self.session = SimpleNamespace(
            caller_name="Ali",
            intent="",
            summary="",
            transcript="",
            result="",
            notes=[],
            note=lambda text: self.session.notes.append(text),
        )
        self.reader = SimpleNamespace(audio=queue.Queue())
        self._stop_pump = threading.Event()
        self._pump_thread = threading.Thread(target=lambda: None)
        self._pump_thread.start()
        self.sent_pcm: list[bytes] = []
        self.conn = None
        self.saved_result = ""
        self._gone = False

    def gone(self) -> bool:
        return self._gone

    def _send_pcm(self, frame: bytes) -> None:
        self.sent_pcm.append(frame)

    def save(self, result: str) -> None:
        self.saved_result = result
        self.session.result = result


class RealtimeTest(unittest.TestCase):
    def test_ulaw_roundtrip(self) -> None:
        pcm = struct.pack("<8h", 0, 1000, -1000, 8000, -8000, 16000, -16000, 32000)
        ulaw = pcm16_to_ulaw(pcm)
        self.assertEqual(8, len(ulaw))
        restored = struct.unpack("<8h", ulaw_to_pcm16(ulaw))
        self.assertEqual(0, restored[0])
        self.assertAlmostEqual(1000, restored[1], delta=100)
        self.assertAlmostEqual(-8000, restored[4], delta=300)

    def test_silence_clock_tells_the_model_then_gives_up(self) -> None:
        call = _FakeCall("ws://localhost/")
        call.config["retries"] = 1
        notes: list[str] = []
        call.session.note = notes.append
        rt = RealtimeCall(call)
        self.assertIsNone(rt._silence_note())                       # the clock has just started
        rt._last_activity -= SILENCE_NUDGE_SECONDS + 1
        rt._out_q.put(b"x")
        self.assertIsNone(rt._silence_note())                       # the model is still speaking
        rt._out_q.get()
        rt._last_frame = time.monotonic()                           # frames arrive, nobody talks
        note = rt._silence_note()
        self.assertIn("Phone line note, not the caller: the caller said nothing", note)
        self.assertIsNone(rt._silence_note())                       # the clock restarted
        rt._heard(struct.pack("<160h", *([3000, -3000] * 80)))       # speech restarts it, and the count of silences
        self.assertLess(time.monotonic() - rt._last_activity, 1)
        self.assertEqual(rt._nudges, 0)
        rt._nudges = 0                                              # (speech reset it above; count one again)
        rt._last_activity -= SILENCE_NUDGE_SECONDS + 1
        rt._last_frame = time.monotonic()
        self.assertIsNotNone(rt._silence_note())
        rt._last_activity -= SILENCE_NUDGE_SECONDS + 1
        rt._last_frame = 0.0                                        # and now no frames at all
        self.assertIsNone(rt._silence_note())                       # second time in a row: the limit
        self.assertTrue(rt._gave_up and rt._stop.is_set())
        self.assertEqual(notes, ["caller turn: silence (1 of 2)", "caller turn: silence (1 of 2)", "caller turn: no_audio (2 of 2)"])

    def test_given_up_on_silence_goes_to_the_not_understood_destination(self) -> None:
        session = CallSession(1, "test", "100", "200", "test-call")
        session.config = {"conversation": True, "intents": [],
                          "realtime_cloud": {"api_key": "test", "ws_url": "ws://localhost/"}}
        registry = SimpleNamespace(results=Mock())
        call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        realtime = RealtimeCall(call)
        call._pump_thread.start()
        with patch("aipbx_ai.realtime.RealtimeSocket"), \
                patch.object(realtime, "_run_openai_protocol", side_effect=lambda _ws: setattr(realtime, "_gave_up", True)):
            self.assertTrue(realtime.run())
        self.assertEqual("silence", registry.results.save.call_args.args[0]["result"])

    def test_silence_pump_handover_preserves_call_and_saves_dialplan_result(self) -> None:
        for tool, arguments, status, route in (
            ("transfer_call", {"target": "sales", "summary": "Caller wants sales"}, "matched", "0123456789abcdef"),
            ("end_call", {"summary": "Finished"}, "ended", None),
        ):
            with self.subTest(tool=tool):
                session = CallSession(1, "test", "100", "200", "test-call")
                session.config = {
                    "conversation": True,
                    "intents": [{"id": "sales", "name": "Sales", "route_id": "0123456789abcdef"}],
                    "realtime_cloud": {"api_key": "test", "ws_url": "ws://localhost/"},
                }
                registry = SimpleNamespace(results=Mock())
                call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
                realtime = RealtimeCall(call)

                def provider(_ws):
                    self.assertFalse(call.gone())
                    self.assertTrue(realtime._execute_tool(tool, arguments)[0])

                call._pump_thread.start()
                with patch("aipbx_ai.realtime.RealtimeSocket"), patch.object(realtime, "_run_openai_protocol", side_effect=provider):
                    self.assertTrue(realtime.run())
                saved = registry.results.save.call_args.args[0]
                self.assertEqual(status, saved["result"])
                self.assertEqual(status, session.result)
                self.assertEqual(route, saved.get("route_id"))
                self.assertEqual(arguments["summary"], saved["summary"])

    def test_realtime_call_audio_barge_in_and_transfer(self) -> None:
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.bind(("127.0.0.1", 0))
        srv.listen(1)
        port = srv.getsockname()[1]
        received: list[dict] = []

        def run_srv() -> None:
            conn, _ = srv.accept()
            with conn:
                req = b""
                while b"\r\n\r\n" not in req:
                    req += conn.recv(1024)
                key = ""
                for line in req.decode("ascii").split("\r\n"):
                    if line.lower().startswith("sec-websocket-key:"):
                        key = line.split(":", 1)[1].strip()
                accept = base64.b64encode(hashlib.sha1((key + _WS_GUID).encode("ascii")).digest()).decode("ascii")
                conn.sendall(
                    (
                        "HTTP/1.1 101 Switching Protocols\r\n"
                        "Upgrade: websocket\r\n"
                        "Connection: Upgrade\r\n"
                        f"Sec-WebSocket-Accept: {accept}\r\n\r\n"
                    ).encode("ascii")
                )
                # Read session.update + response.create
                received.append(_ws_read_client_frame(conn) or {})
                received.append(_ws_read_client_frame(conn) or {})

                # Send audio delta, then speech_started (barge-in), then function call transfer_call
                audio_b64 = base64.b64encode(b"\xff" * 160).decode("ascii")
                conn.sendall(_ws_server_frame(json.dumps({"type": "response.output_audio.delta", "item_id": "it1", "delta": audio_b64}).encode()))
                conn.sendall(_ws_server_frame(json.dumps({"type": "input_audio_buffer.speech_started"}).encode()))
                conn.sendall(_ws_server_frame(json.dumps({"type": "response.output_audio_transcript.done", "transcript": "Aktarıyorum."}).encode()))
                conn.sendall(_ws_server_frame(json.dumps({
                    "type": "response.function_call_arguments.done",
                    "name": "transfer_call",
                    "call_id": "fc1",
                    "arguments": json.dumps({"target": "sales", "summary": "Satış talebi"}),
                }).encode()))
                # Read remaining client events (truncate / cancel / function_call_output)
                conn.settimeout(0.3)
                while True:
                    try:
                        msg = _ws_read_client_frame(conn)
                        if msg is None:
                            break
                        received.append(msg)
                    except OSError:
                        break

        t = threading.Thread(target=run_srv, daemon=True)
        t.start()
        try:
            call = _FakeCall(f"ws://127.0.0.1:{port}/v1/realtime")
            ok = RealtimeCall(call).run()
            self.assertTrue(ok)
            self.assertEqual("sales", call.session.intent)
            self.assertEqual("Satış talebi", call.session.summary)
            self.assertIn("Assistant: Aktarıyorum.", call.session.transcript)
            self.assertEqual("matched", call.saved_result)
            types = [m.get("type") for m in received]
            self.assertIn("session.update", types)
            config = received[0]["session"]
            self.assertEqual("realtime", config["type"])
            self.assertEqual(["audio"], config["output_modalities"])
            self.assertEqual({"type": "audio/pcmu"}, config["audio"]["input"]["format"])
            self.assertEqual({"type": "audio/pcmu"}, config["audio"]["output"]["format"])
            self.assertIn("response.cancel", types)
            self.assertIn("conversation.item.truncate", types)
            self.assertIn("conversation.item.create", types)
        finally:
            t.join(timeout=2.0)
            srv.close()

    def test_realtime_with_listen_q_and_validate_config(self) -> None:
        from aipbx_ai import voice_requests

        validated = voice_requests.validate_config({
            "type": "voice_requests",
            "tts_model": "cloud:openai",
            "stt_model": "cloud:openai",
            "tts_cloud": {"provider": "openai", "api_key": "sk-tts-123456", "lang": "tr"},
            "stt_cloud": {"provider": "openai", "api_key": "sk-stt-123456", "lang": "tr"},
            "understand": "cloud",
            "llm_cloud": {"provider": "openai", "api_key": "sk-llm-123456"},
            "greeting": "Merhaba",
            "conversation": True,
            "intents": [{"id": "sales", "name": "Sales", "examples": ["satış"]}],
            "realtime_cloud": {
                "provider": "openai_compat", "protocol": "openai_ga",
                "ws_url": "wss://voice.example.test/realtime", "model": "voice-model",
                "api_key": "sk-rt-123456", "voice": "coral",
            },
            "pbx_tools": [{"name": "find_person", "description": "Find person"}],
            "pbx_tools_key": "secret-tool-key",
        })
        self.assertIn("realtime_cloud", validated)
        self.assertEqual("coral", validated["realtime_cloud"]["voice"])
        self.assertEqual("openai_ga", validated["realtime_cloud"]["protocol"])
        self.assertEqual("voice-model", validated["realtime_cloud"]["model"])
        self.assertEqual("wss://voice.example.test/realtime", validated["realtime_cloud"]["ws_url"])
        self.assertEqual("find_person", validated["pbx_tools"][0]["name"])
        pub = voice_requests.public(validated)
        self.assertNotIn("api_key", pub["realtime_cloud"])
        self.assertTrue(pub["realtime_cloud"]["api_key_set"])
        self.assertNotIn("pbx_tools_key", pub)

        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.bind(("127.0.0.1", 0))
        srv.listen(1)
        port = srv.getsockname()[1]
        received: list[dict] = []

        def run_srv() -> None:
            conn, _ = srv.accept()
            with conn:
                req = b""
                while b"\r\n\r\n" not in req:
                    req += conn.recv(1024)
                key = ""
                for line in req.decode("ascii").split("\r\n"):
                    if line.lower().startswith("sec-websocket-key:"):
                        key = line.split(":", 1)[1].strip()
                accept = base64.b64encode(hashlib.sha1((key + _WS_GUID).encode("ascii")).digest()).decode("ascii")
                conn.sendall(
                    (
                        "HTTP/1.1 101 Switching Protocols\r\n"
                        "Upgrade: websocket\r\n"
                        "Connection: Upgrade\r\n"
                        f"Sec-WebSocket-Accept: {accept}\r\n\r\n"
                    ).encode("ascii")
                )
                conn.settimeout(1.0)
                while len(received) < 3:
                    try:
                        msg = _ws_read_client_frame(conn)
                        if msg is None:
                            break
                        received.append(msg)
                    except OSError:
                        break
                conn.sendall(_ws_server_frame(json.dumps({
                    "type": "response.function_call_arguments.done",
                    "name": "end_call",
                    "call_id": "fc2",
                    "arguments": json.dumps({"summary": "Bitti"}),
                }).encode()))

        t = threading.Thread(target=run_srv, daemon=True)
        t.start()
        try:
            call = _FakeCall(f"ws://127.0.0.1:{port}/v1/realtime")
            call.reader = SimpleNamespace()  # No .audio attribute, simulates real _Reader + listen_q
            call.session.listen_q = None
            pcm = struct.pack("<8h", 100, -100, 200, -200, 300, -300, 400, -400)

            def feed_audio() -> None:
                import time
                for _ in range(20):
                    q_ref = getattr(call.session, "listen_q", None)
                    if q_ref is not None:
                        q_ref.put(("audio", 0.0, pcm))
                        return
                    time.sleep(0.02)

            ft = threading.Thread(target=feed_audio, daemon=True)
            ft.start()
            ok = RealtimeCall(call).run()
            self.assertTrue(ok)
            self.assertEqual("_end", call.session.intent)
            self.assertIsNone(call.session.listen_q)
            self.assertTrue(any(m.get("type") == "input_audio_buffer.append" for m in received))
            ft.join(timeout=1.0)
        finally:
            t.join(timeout=2.0)
            srv.close()

    def test_gemini_live_and_qwen_url_resolution(self) -> None:
        pcm8 = struct.pack("<4h", 0, 1000, 2000, 3000)
        pcm16 = resample_pcm16(pcm8, 8000, 16000)
        self.assertEqual(16, len(pcm16))
        pcm24 = struct.pack("<6h", 100, 101, 102, 200, 201, 202)
        down8 = struct.unpack("<2h", resample_pcm16(pcm24, 24000, 8000))
        self.assertEqual((101, 201), down8)

        url, headers = _resolve_ws_endpoint({"model": "gpt-4o-realtime-preview", "api_key": "fixture"})
        self.assertEqual("wss://api.openai.com/v1/realtime?model=gpt-realtime", url)
        self.assertEqual({"Authorization": "Bearer fixture"}, headers)

        q_url, q_hdr = _resolve_ws_endpoint({"provider": "qwen", "api_key": "q-key", "model": "qwen-omni-turbo-realtime"})
        self.assertIn("dashscope-intl.aliyuncs.com/api-ws/v1/realtime", q_url)
        self.assertEqual("Bearer q-key", q_hdr["Authorization"])

        c_url, c_hdr = _resolve_ws_endpoint({
            "provider": "openai_compat",
            "base_url": "https://rt.example.com/v1/",
            "model": "ultravox-v0.5",
        })
        self.assertEqual("wss://rt.example.com/v1/realtime?model=ultravox-v0.5", c_url)
        self.assertNotIn("Authorization", c_hdr)
        self.assertEqual("realtime=v1", c_hdr["OpenAI-Beta"])

        ga_url, ga_headers = _resolve_ws_endpoint({
            "provider": "openai_compat",
            "protocol": "openai_ga",
            "ws_url": "wss://voice.example.com/realtime",
            "model": "voice-model",
            "api_key": "test-key",
        })
        self.assertEqual("wss://voice.example.com/realtime?model=voice-model", ga_url)
        self.assertEqual({"Authorization": "Bearer test-key"}, ga_headers)

        g_url, g_hdr = _resolve_ws_endpoint({"provider": "google_ai", "api_key": "g-key"})
        self.assertIn("BidiGenerateContent?key=g-key", g_url)
        self.assertEqual({}, g_hdr)

        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.bind(("127.0.0.1", 0))
        srv.listen(1)
        port = srv.getsockname()[1]
        received: list[dict] = []

        def run_srv() -> None:
            conn, _ = srv.accept()
            with conn:
                req = b""
                while b"\r\n\r\n" not in req:
                    req += conn.recv(1024)
                key = ""
                for line in req.decode("ascii").split("\r\n"):
                    if line.lower().startswith("sec-websocket-key:"):
                        key = line.split(":", 1)[1].strip()
                accept = base64.b64encode(hashlib.sha1((key + _WS_GUID).encode("ascii")).digest()).decode("ascii")
                conn.sendall(
                    (
                        "HTTP/1.1 101 Switching Protocols\r\n"
                        "Upgrade: websocket\r\n"
                        "Connection: Upgrade\r\n"
                        f"Sec-WebSocket-Accept: {accept}\r\n\r\n"
                    ).encode("ascii")
                )
                received.append(_ws_read_client_frame(conn) or {})
                time.sleep(0.25)
                conn.sendall(_ws_server_frame(b'{"setupComplete":{}}'))
                received.append(_ws_read_client_frame(conn) or {})
                received.append(_ws_read_client_frame(conn) or {})
                audio24_b64 = base64.b64encode(struct.pack("<6h", 10, 20, 30, 40, 50, 60)).decode("ascii")
                conn.sendall(_ws_server_frame(json.dumps({
                    "serverContent": {
                        "modelTurn": {
                            "parts": [
                                {"text": "Hoş geldiniz, hemen bağlıyorum."},
                                {"inlineData": {"mimeType": "audio/pcm;rate=24000", "data": audio24_b64}},
                            ]
                        }
                    }
                }).encode(), opcode=0x2))
                conn.sendall(_ws_server_frame(json.dumps({
                    "toolCall": {
                        "functionCalls": [{
                            "id": "gfc1",
                            "name": "transfer_call",
                            "args": {"target": "sales", "summary": "Satış talebi Gemini"},
                        }]
                    }
                }).encode(), opcode=0x2))
                conn.settimeout(0.3)
                while True:
                    try:
                        msg = _ws_read_client_frame(conn)
                        if msg is None:
                            break
                        received.append(msg)
                    except OSError:
                        break

        t = threading.Thread(target=run_srv, daemon=True)
        t.start()
        try:
            call = _FakeCall(f"ws://127.0.0.1:{port}/ws")
            call.config["realtime_cloud"] = {
                "provider": "google_ai",
                "api_key": "g-test-1234",
                "model": "",
                "voice": "coral",
                "lang": "tr",
                "ws_url": f"ws://127.0.0.1:{port}/ws",
            }
            call.reader.audio.put(struct.pack("<160h", *([50] * 160)))
            ok = RealtimeCall(call).run()
            self.assertTrue(ok)
            self.assertEqual("sales", call.session.intent)
            self.assertEqual("Satış talebi Gemini", call.session.summary)
            self.assertIn("Assistant: Hoş geldiniz, hemen bağlıyorum.", call.session.transcript)
            self.assertIn("setup", received[0])
            self.assertEqual("models/gemini-3.8-live", received[0]["setup"]["model"])
            self.assertIn("text", received[1]["realtimeInput"])
            self.assertEqual("audio/pcm;rate=16000", received[2]["realtimeInput"]["audio"]["mimeType"])
            self.assertEqual("Kore", received[0]["setup"]["generationConfig"]["speechConfig"]["voiceConfig"]["prebuiltVoiceConfig"]["voiceName"])
            self.assertTrue(any("toolResponse" in m for m in received))
        finally:
            t.join(timeout=2.0)
            srv.close()

    def test_realtime_exposes_db_tool_renders_vars_and_forwards_dtmf(self) -> None:
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.session.caller = "05320001122"
        call.session.did = "02120000000"
        call.session.verified_customer = "CUST-77"
        call.session.dtmf = []
        call.config["greeting"] = "Merhaba {caller_name}"
        call.config["instructions"] = "Hello {caller_name}, ID={customer_id}, Phone={caller_id}"
        call.config["db_queries"] = [
            {"id": "fatura_sorgu", "description": "Invoice amount", "params": []},
        ]
        looked_up: list[tuple] = []
        call.resolve_lookup = lambda tool, query: looked_up.append((tool, query)) or '{"status":"found","value":"250 TL"}'

        rt = RealtimeCall(call)
        instr = rt._instructions()
        self.assertIn("Start by greeting the caller: Merhaba Ali", instr)
        self.assertIn("Hello Ali, ID=CUST-77, Phone=05320001122", instr)
        tools = {t["name"]: t for t in rt._build_tools()}
        self.assertIn("get_db_value", tools)
        self.assertEqual(["fatura_sorgu"], tools["get_db_value"]["parameters"]["properties"]["query_id"]["enum"])

        ended, out = rt._execute_tool("get_db_value", {"query_id": "fatura_sorgu", "params": {}})
        self.assertFalse(ended)
        self.assertIn("250 TL", out)
        self.assertEqual([("get_db_value", {"query_id": "fatura_sorgu", "params": {}})], looked_up)

        call._gone = True
        ended_dtmf, out_dtmf = rt._execute_tool("collect_dtmf", {"max_digits": "bad", "timeout_sec": "nope"})
        self.assertFalse(ended_dtmf)
        self.assertEqual({"ok": True, "digits": ""}, json.loads(out_dtmf))

    def test_pbx_tools_transfer_call_without_intents_sets_matched_and_saves(self) -> None:
        session = CallSession(1, "test", "100", "200", "test-pbx-xfer")
        session.did = "02120001111"
        session.verified_customer = "CUST-99"
        session.config = {
            "conversation": True,
            "intents": [],
            "pbx_tools": [
                {
                    "name": "transfer_call",
                    "description": "Transfer to extension",
                    "parameters": {"type": "object", "properties": {"target": {"type": "string"}}, "required": ["target"]},
                }
            ],
            "pbx_tools_key": "k1",
            "realtime_cloud": {"api_key": "test", "ws_url": "ws://localhost/"},
        }
        registry = SimpleNamespace(results=Mock())
        call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        call._pump_thread.start()
        rt = RealtimeCall(call)

        tool_names = [t["name"] for t in rt._build_tools()]
        self.assertEqual(1, tool_names.count("transfer_call"))

        with patch("aipbx_ai.pbx_tools.PbxToolsClient.execute", return_value={
            "ok": True,
            "kind": "action",
            "text": "Transferring to Ahmet (7891).",
            "action": {"type": "transfer", "target": "7891"},
        }) as mock_exec:
            ended, out = rt._execute_tool("transfer_call", {"target": "7891"})

        self.assertTrue(ended)
        self.assertEqual("Transferring to Ahmet (7891).", out)
        self.assertEqual("7891", session.intent)
        self.assertEqual({"id": "7891", "name": "7891"}, call.matched)
        caller_arg = mock_exec.call_args.kwargs["caller"]
        self.assertEqual("02120001111", caller_arg["did"])
        self.assertEqual("CUST-99", caller_arg["verified_customer"])

        call.save("matched")
        saved = registry.results.save.call_args.args[0]
        self.assertEqual("7891", saved["intent"])



class _RecordingSocket:
    def __init__(self) -> None:
        self.sent: list[dict] = []

    def send_json(self, obj: dict) -> None:
        self.sent.append(obj)

    def recv_json(self):
        return None


class RealtimeSettingsTest(unittest.TestCase):
    def _session(self, **rc) -> dict:
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.config["realtime_cloud"].update(rc)
        ws = _RecordingSocket()
        RealtimeCall(call)._run_openai_protocol(ws)
        return ws.sent[0]["session"]

    def test_vad_noise_reduction_and_temperature_come_from_the_config(self) -> None:
        default = self._session()
        self.assertEqual((0.5, 500), (default["audio"]["input"]["turn_detection"]["threshold"],
                                      default["audio"]["input"]["turn_detection"]["silence_duration_ms"]))
        self.assertNotIn("noise_reduction", default["audio"]["input"])
        tuned = self._session(vad={"threshold": 0.7, "silence_ms": 900}, noise_reduction="far_field")
        turn = tuned["audio"]["input"]["turn_detection"]
        self.assertEqual((0.7, 900), (turn["threshold"], turn["silence_duration_ms"]))
        self.assertEqual({"type": "far_field"}, tuned["audio"]["input"]["noise_reduction"])
        beta = self._session(provider="openai_compat", protocol="openai_beta", temperature=0.8, noise_reduction="near_field")
        self.assertEqual(0.8, beta["temperature"])
        self.assertNotIn("noise_reduction", beta)       # OpenAI only

    def test_company_instructions_come_before_the_caller_context(self) -> None:
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.session.verified_customer = "CUST-1"
        text = RealtimeCall(call)._instructions()
        self.assertLess(text.index("Help the caller."), text.index("Caller name: Ali"))
        self.assertLess(text.index("Help the caller."), text.index("Start by greeting"))

    def test_call_language_goes_into_the_instructions_and_the_greeting(self) -> None:
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.session.lang, call.session.lang_name = "en", "English"
        call.session.greeting = "Hello {caller_name}, welcome."
        text = RealtimeCall(call)._instructions()
        self.assertIn("Speak English", text)
        self.assertIn("Start by greeting the caller: Hello Ali, welcome.", text)
        self.assertNotIn("Merhaba", text)
        del call.session.lang, call.session.lang_name, call.session.greeting      # an older session: the app's own
        self.assertIn("Start by greeting the caller: Merhaba", RealtimeCall(call)._instructions())

    def test_knowledge_sources_in_the_tool_and_routed_by_source(self) -> None:
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.config.update(knowledge=True, knowledge_sources=[
            {"id": 3, "name": "Prices", "description": "price list"}, {"id": 5, "name": "Hours", "description": ""}])
        seen: list[tuple] = []
        call.resolve_lookup = lambda tool, query, source=None: seen.append((tool, query, source)) or "ok"
        rt = RealtimeCall(call)
        lookup = next(t for t in rt._build_tools() if t["name"] == "lookup")
        self.assertEqual([3, 5], lookup["parameters"]["properties"]["source"]["enum"])
        self.assertIn("3: Prices - price list", lookup["description"])
        rt._execute_tool("lookup", {"tool": "knowledge", "query": "price", "source": 3})
        self.assertEqual([("knowledge", "price", 3)], seen)

    def test_unreachable_provider_ends_as_hangup_without_a_cascade(self) -> None:
        call = _FakeCall("ws://127.0.0.1:1/rt")           # nothing listens there
        self.assertFalse(RealtimeCall(call).run())
        self.assertEqual("hangup", call.saved_result)
        self.assertEqual("hangup", call.session.result)
        self.assertTrue(any("Realtime provider unreachable" in n for n in call.session.notes))

    def test_gemini_setup_converts_integer_enums_to_strings(self) -> None:
        from unittest.mock import patch
        call = _FakeCall("ws://127.0.0.1:1/rt")
        call.config["realtime_cloud"]["provider"] = "google_ai"
        call.config["realtime_cloud"]["model"] = "gemini-3.1-flash-live-preview"
        call.config.update(knowledge=True, knowledge_sources=[{"id": 3, "name": "Prices", "description": ""}])
        sent = []

        class _FakeWs:
            def send_json(self, obj):
                sent.append(obj)
            def recv_json(self):
                return {"setupComplete": {}}

        rt = RealtimeCall(call)
        rt._stop.set()
        with patch.object(rt, "_pump_upstream"):
            rt._run_gemini(_FakeWs())
        decls = sent[0]["setup"]["tools"][0]["functionDeclarations"]
        lookup_decl = next(d for d in decls if d["name"] == "lookup")
        source_prop = lookup_decl["parameters"]["properties"]["source"]
        self.assertEqual(source_prop["type"], "string")
        self.assertEqual(source_prop["enum"], ["3"])
        self.assertEqual(sent[0]["setup"]["generationConfig"]["speechConfig"]["languageCode"], "tr-TR")
        self.assertEqual(sent[0]["setup"]["inputAudioTranscription"], {})
        self.assertEqual(sent[0]["setup"]["outputAudioTranscription"], {})
        self.assertEqual(sent[0]["setup"]["realtimeInputConfig"]["automaticActivityDetection"]["silenceDurationMs"], 700)
        self.assertEqual(sent[0]["setup"]["realtimeInputConfig"]["automaticActivityDetection"]["prefixPaddingMs"], 300)
        self.assertEqual(sent[0]["setup"]["realtimeInputConfig"]["activityHandling"], "START_OF_ACTIVITY_INTERRUPTS")
        sys_txt = sent[0]["setup"]["systemInstruction"]["parts"][0]["text"]
        self.assertIn("CRITICAL LANGUAGE RULE: Always speak and reply ONLY in Turkish", sys_txt)

    def test_gemini_streaming_transcriptions_fill_history_heard_and_transcript(self) -> None:
        session = CallSession(7, "realtime", "19000", "", "gem-tr-call")
        session.config = {
            "language": "tr",
            "language_name": "Turkish",
            "conversation": True,
            "intents": [],
            "realtime_cloud": {"provider": "google_ai", "api_key": "g-test", "lang": "tr"},
        }
        registry = SimpleNamespace(results=Mock())
        call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        call._pump_thread.start()
        rt = RealtimeCall(call)
        events = [
            {"setupComplete": {}},
            {"serverContent": {"inputTranscription": {"text": "Fatura "}}},
            {"serverContent": {"inputTranscription": {"text": "durumum nedir?"}}},
            {"serverContent": {"outputTranscription": {"text": "Hemen kontrol "}}},
            {"serverContent": {"outputTranscription": {"text": "ediyorum."}}},
            {"serverContent": {"turnComplete": True}},
            None,
        ]
        sent = []

        class _FakeWs:
            close_code = 1000
            close_reason = ""
            def send_json(self, obj):
                sent.append(obj)
            def recv_json(self):
                return events.pop(0) if events else None
            def close(self):
                pass

        with patch("aipbx_ai.realtime.RealtimeSocket", return_value=_FakeWs()):
            self.assertTrue(rt.run())
        self.assertEqual(call.history, [("caller", "Fatura durumum nedir?"), ("assistant", "Hemen kontrol ediyorum.")])
        self.assertEqual(session.heard, ["Fatura durumum nedir?"])
        self.assertIn("Caller: Fatura durumum nedir?", session.transcript)
        self.assertIn("Assistant: Hemen kontrol ediyorum.", session.transcript)

    def test_stateful_resamplers_secret_ref_close_reason_and_silence_accounting(self) -> None:
        up = _Upsampler8to16()
        c1 = struct.pack("<4h", 0, 1000, 2000, 3000)
        c2 = struct.pack("<4h", 4000, 5000, 6000, 7000)
        out1 = struct.unpack("<6h", up.process(c1))
        out2 = struct.unpack("<8h", up.process(c2))
        self.assertEqual(out1[:4], (0, 500, 1000, 1500))
        self.assertEqual(out2[:2], (3000, 3500))

        down = _Downsampler24to8()
        d1 = struct.unpack("<2h", down.process(struct.pack("<7h", 100, 100, 100, 200, 200, 200, 300)))
        d2 = struct.unpack("<1h", down.process(struct.pack("<2h", 300, 300)))
        d3 = struct.unpack("<1h", down.process(struct.pack("<3h", 300, 300, 300)))
        self.assertEqual((len(d1), len(d2), len(d3)), (2, 1, 1))
        self.assertEqual(d2[0], 289)   # 5-tap LPF smooths the 200->300 transition across chunks
        self.assertEqual(d3[0], 300)

        # Secret reference resolution does not mutate cfg["realtime_cloud"].
        session = CallSession(7, "realtime", "19000", "", "gem-ref-call")
        session.config = {
            "language": "tr",
            "conversation": True,
            "intents": [],
            "realtime_cloud": {"provider": "google_ai", "secret_ref": "app:7:realtime:google_ai", "lang": "tr"},
        }
        call = VoiceRequestsCall(SimpleNamespace(results=Mock()), Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        call._pump_thread.start()
        rt = RealtimeCall(call)

        def check_ws(url, headers):
            self.assertIn("key=resolved-g-key", url)
            return SimpleNamespace(send_json=lambda _o: None, recv_json=lambda: {"setupComplete": {}}, close=lambda: None, close_code=1000, close_reason="")

        with patch("aipbx_ai.secret_refs.resolve", return_value={"provider": "google_ai", "api_key": "resolved-g-key"}) as m_res, \
                patch("aipbx_ai.realtime.RealtimeSocket", side_effect=check_ws), \
                patch.object(rt, "_run_gemini", return_value=None):
            self.assertTrue(rt.run())
        self.assertEqual(m_res.call_count, 1)
        self.assertNotIn("api_key", call.config["realtime_cloud"])

        # Silence frames in _pump_playback do not inflate session.sent_bytes.
        session.sent_bytes = 0
        call.conn = SimpleNamespace(sendall=lambda data: None)
        rt2 = RealtimeCall(call)
        rt2._enqueue_pcm(b"\x01\x00" * 160)
        threading.Timer(0.08, rt2._stop.set).start()
        rt2._pump_playback()
        self.assertEqual(session.sent_bytes, 320)

        # RealtimeSocket parses close code and reason on opcode 0x8.
        sock = RealtimeSocket.__new__(RealtimeSocket)
        sock.close_code = None
        sock.close_reason = ""
        sock.reader = SimpleNamespace(read_frame=lambda: (0x8, struct.pack("!H", 1007) + b"Invalid language"))
        self.assertIsNone(sock.recv_json())
        self.assertEqual((sock.close_code, sock.close_reason), (1007, "Invalid language"))

    def test_closing_turn_speaks_handover_and_drains_audio_before_transfer(self) -> None:
        # Gemini Live: after transfer_call, waits for model's closing speech & drains _out_q before exit.
        session = CallSession(7, "realtime", "19000", "976286", "gem-xfer-close")
        session.config = {
            "language": "tr",
            "language_name": "Turkish",
            "conversation": True,
            "intents": [{"id": "muhasebe", "name": "Muhasebe"}],
            "realtime_cloud": {"provider": "google_ai", "api_key": "g-test", "lang": "tr"},
        }
        registry = SimpleNamespace(results=Mock())
        call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        call.sent_pcm = []
        call._send_pcm = lambda frame: call.sent_pcm.append(frame)
        call._pump_thread.start()
        rt = RealtimeCall(call)
        audio24_b64 = base64.b64encode(struct.pack("<480h", *([600, -600] * 240))).decode("ascii")
        tool_resp_seen = threading.Event()
        sent_to_ws: list[dict] = []

        class _GeminiClosingWs:
            close_code = 1000
            close_reason = ""

            def __init__(self) -> None:
                self._step = 0

            def send_json(self, obj: dict) -> None:
                sent_to_ws.append(obj)
                if "toolResponse" in obj:
                    tool_resp_seen.set()

            def recv_json(self):
                self._step += 1
                if self._step == 1:
                    return {"setupComplete": {}}
                if self._step == 2:
                    return {"toolCall": {"functionCalls": [{"id": "c1", "name": "transfer_call", "args": {"target": "muhasebe", "summary": "Muhasebe talebi"}}]}}
                if self._step == 3:
                    return {"serverContent": {"generationComplete": True}}
                if self._step == 4:
                    return {"serverContent": {"turnComplete": True}}
                if self._step == 5:
                    tool_resp_seen.wait(timeout=1.0)
                    return {"serverContent": {
                        "modelTurn": {"parts": [{"inlineData": {"mimeType": "audio/pcm;rate=24000", "data": audio24_b64}}]},
                        "outputTranscription": {"text": "Hemen muhasebe birimine aktarıyorum, iyi günler."},
                    }}
                if self._step == 6:
                    return {"serverContent": {"generationComplete": True}}
                raise AssertionError("Should exit cleanly on post-audio generationComplete without waiting for socket close")

            def close(self) -> None:
                pass

        t0 = time.monotonic()
        with patch("aipbx_ai.realtime.RealtimeSocket", return_value=_GeminiClosingWs()):
            self.assertTrue(rt.run())
        self.assertLess(time.monotonic() - t0, 2.0)
        self.assertTrue(tool_resp_seen.is_set())
        tool_resp = next(m["toolResponse"]["functionResponses"][0]["response"]["result"] for m in sent_to_ws if "toolResponse" in m)
        self.assertEqual("transferring", json.loads(tool_resp)["status"])
        self.assertEqual("muhasebe", session.intent)
        self.assertEqual("matched", session.result)
        self.assertIn("Assistant: Hemen muhasebe birimine aktarıyorum, iyi günler.", session.transcript)
        self.assertTrue(any(frame != b"\x00" * 320 for frame in call.sent_pcm))

        # OpenAI Realtime: after transfer_call, sends response.create and waits for closing audio + response.done.
        call2 = _FakeCall("ws://127.0.0.1:1/rt")
        rt2 = RealtimeCall(call2)
        rt2._upstream_ready.set()
        oa_sent: list[dict] = []
        audio_ulaw = base64.b64encode(b"\x55" * 160).decode("ascii")
        oa_events = [
            {"type": "response.function_call_arguments.done", "name": "transfer_call", "call_id": "oc1",
             "arguments": json.dumps({"target": "sales", "summary": "Satış talebi"})},
            {"type": "response.done"},
            {"type": "response.output_audio.delta", "item_id": "i2", "delta": audio_ulaw},
            {"type": "response.output_audio_transcript.done", "transcript": "Hemen aktarıyorum."},
            {"type": "response.done"},
            {"type": "error", "error": {"message": "unreachable after response.done"}},
        ]

        class _OpenAiClosingWs:
            def send_json(self, obj: dict) -> None:
                oa_sent.append(obj)

            def recv_json(self):
                return oa_events.pop(0) if oa_events else None

        rt2._run_openai_protocol(_OpenAiClosingWs())
        self.assertEqual("sales", call2.session.intent)
        self.assertEqual(["Assistant: Hemen aktarıyorum."], rt2._transcript_lines)
        self.assertEqual([m.get("type") for m in oa_sent][-2:], ["conversation.item.create", "response.create"])
        self.assertFalse(rt2._out_q.empty())
        rt2._drain_playback(timeout=0.05)

        # Instructions and tool descriptions carry transfer/unavailable politeness rules & local time.
        instr = rt._instructions()
        self.assertIn("TRANSFER & UNAVAILABLE RULES", instr)
        self.assertIn("Current local date and time:", instr)
        self.assertIn("never UTC", instr)
        call.config["timezone"] = "Europe/Istanbul"
        instr_tr = RealtimeCall(call)._instructions()
        self.assertIn("+03:00", instr_tr)
        xfer_tool = next(t for t in rt._build_tools() if t["name"] == "transfer_call")
        self.assertIn("do NOT call transfer_call", xfer_tool["description"])

    def test_closing_turn_skips_duplicate_prompt_when_model_already_spoke_in_same_turn(self) -> None:
        session = CallSession(7, "realtime", "19000", "976286", "gem-xfer-already-spoke")
        session.config = {
            "language": "tr",
            "language_name": "Turkish",
            "conversation": True,
            "intents": [{"id": "muhasebe", "name": "Muhasebe"}],
            "realtime_cloud": {"provider": "google_ai", "api_key": "g-test", "lang": "tr"},
        }
        registry = SimpleNamespace(results=Mock())
        call = VoiceRequestsCall(registry, Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        call.sent_pcm = []
        call._send_pcm = lambda frame: call.sent_pcm.append(frame)
        call._pump_thread.start()
        rt = RealtimeCall(call)
        audio24_b64 = base64.b64encode(struct.pack("<480h", *([700, -700] * 240))).decode("ascii")
        sent_to_ws: list[dict] = []

        class _GeminiAlreadySpokeWs:
            close_code = 1000
            close_reason = ""

            def __init__(self) -> None:
                self._step = 0

            def send_json(self, obj: dict) -> None:
                sent_to_ws.append(obj)

            def recv_json(self):
                self._step += 1
                if self._step == 1:
                    return {"setupComplete": {}}
                if self._step == 2:
                    return {"serverContent": {
                        "modelTurn": {"parts": [{"inlineData": {"mimeType": "audio/pcm;rate=24000", "data": audio24_b64}}]},
                        "outputTranscription": {"text": "Sizi hemen muhasebeye aktarıyorum."},
                    }}
                if self._step == 3:
                    return {"toolCall": {"functionCalls": [{"id": "c2", "name": "transfer_call", "args": {"target": "muhasebe", "summary": "Muhasebe"}}]}}
                raise AssertionError("Should break immediately after sending toolResponse when already spoken in turn")

            def close(self) -> None:
                pass

        with patch("aipbx_ai.realtime.RealtimeSocket", return_value=_GeminiAlreadySpokeWs()):
            self.assertTrue(rt.run())
        tool_resp = next(m["toolResponse"]["functionResponses"][0]["response"]["result"] for m in sent_to_ws if "toolResponse" in m)
        self.assertEqual({"ok": True, "status": "transferring"}, json.loads(tool_resp))
        self.assertEqual("muhasebe", session.intent)
        self.assertEqual("matched", session.result)
        self.assertEqual(["Assistant: Sizi hemen muhasebeye aktarıyorum."], rt._transcript_lines)
        self.assertTrue(any(frame != b"\x00" * 320 for frame in call.sent_pcm))


if __name__ == "__main__":
    unittest.main()
