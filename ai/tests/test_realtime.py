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

from aipbx_ai.calls import CallSession, VoiceRequestsCall

from aipbx_ai.realtime import SILENCE_NUDGE_SECONDS, RealtimeCall, _resolve_ws_endpoint, pcm16_to_ulaw, resample_pcm16, ulaw_to_pcm16

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
        )
        self.reader = SimpleNamespace(audio=queue.Queue())
        self._stop_pump = threading.Event()
        self._pump_thread = threading.Thread(target=lambda: None)
        self._pump_thread.start()
        self.sent_pcm: list[bytes] = []
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
        self.assertEqual((100, 200), down8)

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
                "model": "gemini-2.0-flash-live-001",
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


if __name__ == "__main__":
    unittest.main()
