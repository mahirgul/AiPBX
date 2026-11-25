"""Gateway policy boundary and real loopback transport attacks; stdlib only."""
import http.server
import json
import os
import sys
import socket
import threading
import time
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))
from aipbx_ai import gateway as g


class GatewayTest(unittest.TestCase):
    def payload(self, **changes):
        body = dict(protocol_version=1, request_id='a'*32, operation='chat', input='Hello', max_tokens=300, deadline_at=int(time.time())+30)
        body.update(changes)
        return json.dumps(body).encode()

    def test_untrusted_config_and_unsupported_operation_never_resolve(self):
        for body in [self.payload(api_key='SECRET'), self.payload(operation='transcribe'), self.payload(protocol_version=2), self.payload(deadline_at=0)]:
            resolver = Mock()
            result = g.execute(body, resolver=resolver)
            self.assertEqual(result['status'], 'failed')
            resolver.assert_not_called()
            self.assertNotIn('SECRET', json.dumps(result))

    def test_policy_rejection_never_calls_provider(self):
        runner = Mock()
        result = g.execute(self.payload(), resolver=Mock(side_effect=g.GatewayError('policy_blocked')), runner=runner)
        self.assertEqual(result['error']['code'], 'policy_blocked')
        runner.assert_not_called()

    def test_success_records_resolved_model_and_usage(self):
        ctx = {'connection': {'adapter': 'ollama', 'model':'test'}, 'model_ref':'local:test', 'config_revision':'v1'}
        result = g.execute(self.payload(), resolver=Mock(return_value=ctx), runner=Mock(return_value=(' OK ',12)))
        self.assertEqual(result['output'],'OK')
        self.assertEqual(result['resolved_model_ref'],'local:test')
        self.assertEqual(result['usage']['amount'],12)

    def test_chat_passes_the_local_context_size(self):
        sent = []
        post = lambda url, headers, body, deadline, *a: sent.append(body) or {'message': {'content': 'hi'}}
        g.chat({'adapter': 'ollama', 'model': 'm', 'num_ctx': 8192}, 'p', 50, time.monotonic() + 5, post=post)
        g.chat({'adapter': 'ollama', 'model': 'm'}, 'p', 50, time.monotonic() + 5, post=post)
        self.assertEqual(sent[0]['options']['num_ctx'], 8192)
        self.assertNotIn('num_ctx', sent[1]['options'])
        manager = Mock()
        manager.chat.return_value = 'ok'
        g.chat({'adapter': 'runtime', 'model': 'x', 'num_ctx': 4096}, 'p', 50, time.monotonic() + 5, manager)
        self.assertEqual(manager.chat.call_args.kwargs['num_ctx'], 4096)

    def test_empty_answer_and_provider_exception_are_sanitized(self):
        ctx = {'connection':{}, 'model_ref':'test', 'config_revision':'v1'}
        for runner in [Mock(return_value=('',0)),Mock(side_effect=RuntimeError('secret-key-123'))]:
            result = g.execute(self.payload(), resolver=Mock(return_value=ctx), runner=runner)
            self.assertEqual(result['status'],'failed')
            self.assertNotIn('secret-key',json.dumps(result))

    def test_expired_after_resolution_never_calls_provider(self):
        runner=Mock()
        def resolver(*args):
            raise g.GatewayError('deadline_exceeded')
        result=g.execute(self.payload(),resolver=resolver,runner=runner)
        self.assertEqual(result['error']['code'],'deadline_exceeded')
        runner.assert_not_called()

    def test_real_transport_refuses_redirect_and_does_not_leak_credentials(self):
        requests=[]
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                requests.append(self.path)
                self.send_response(302)
                self.send_header('Location','http://127.0.0.1:'+str(self.server.server_port)+'/stolen')
                self.end_headers()
            def log_message(self,*args):pass
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            with self.assertRaises(g.GatewayError):
                g.post_json('http://127.0.0.1:'+str(server.server_port)+'/original',{'Authorization':'Bearer secret'}, {},time.monotonic()+3,True)
            self.assertEqual(requests,['/original'])
        finally:server.shutdown();server.server_close();thread.join()

    def test_http10_response_closing_connection_is_read_successfully(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                self.rfile.read(int(self.headers['Content-Length']))
                body = b'{"ok": true}'
                self.send_response(200)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            def log_message(self, *args): pass
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            result = g.post_json('http://127.0.0.1:' + str(server.server_port), {}, {}, time.monotonic()+3, True)
            self.assertEqual(result, {'ok': True})
        finally:
            server.shutdown(); server.server_close(); thread.join()

    def test_an_unreachable_first_address_falls_back_to_the_next(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                self.rfile.read(int(self.headers['Content-Length']))
                body = b'{"ok": true}'
                self.send_response(200)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            def log_message(self, *args): pass
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        port = server.server_port
        dead = socket.socket(); dead.bind(('127.0.0.1', 0)); dead_port = dead.getsockname()[1]; dead.close()
        try:
            # A dual-stack name whose first answer refuses the connection (an IPv6 without a route, say).
            with patch.object(g.socket, 'getaddrinfo', return_value=[
                    (socket.AF_INET, socket.SOCK_STREAM, 6, '', ('127.0.0.1', dead_port)),
                    (socket.AF_INET, socket.SOCK_STREAM, 6, '', ('127.0.0.1', port))]):
                result = g.post_json('http://provider.test:%d/chat' % port, {}, {}, time.monotonic()+3, True)
            self.assertEqual(result, {'ok': True})
        finally:
            server.shutdown(); server.server_close(); thread.join()

    def test_metadata_loopback_and_dns_rebinding_rejected(self):
        for url,private in [('http://169.254.169.254/token',True),('https://127.0.0.1/token',False)]:
            with self.assertRaises(g.GatewayError):g.post_json(url,{}, {},time.monotonic()+2,private)
        with patch.object(g.socket,'getaddrinfo',return_value=[(2,1,6,'',('169.254.169.254',443))]):
            with self.assertRaises(g.GatewayError):g.post_json('https://provider.example/chat',{}, {},time.monotonic()+2)

    def test_capacity_is_bounded(self):
        for _ in range(4):self.assertTrue(g._SLOTS.acquire(False))
        try:self.assertEqual(g.execute(self.payload())['error']['code'],'capacity_exceeded')
        finally:
            for _ in range(4):g._SLOTS.release()

    def test_anthropic_adapter_formats_messages_and_counts_tokens(self):
        seen = []
        def fake_post(url, headers, body, deadline, allow_private=False):
            seen.append((url, headers, body))
            return {'content': [{'text': 'OK'}], 'usage': {'input_tokens': 12, 'output_tokens': 3}}
        ans, tokens = g.chat({'adapter': 'anthropic', 'url': 'https://api.anthropic.com/v1/messages',
                              'model': 'claude-sonnet-4-20250514', 'api_key': 'sk-ant-123'}, 'Hello', 100, 9999999999.0, post=fake_post)
        self.assertEqual((ans, tokens), ('OK', 15))
        self.assertEqual(seen[0][1]['x-api-key'], 'sk-ant-123')
        self.assertEqual(seen[0][1]['anthropic-version'], '2023-06-01')

    def test_embed_operation_returns_vector_and_dimensions(self):
        class FakeMgr:
            def embed(self, model_id, texts):
                return [[0.25, 0.5, 0.75]]
        payload = json.dumps({
            'protocol_version': 1, 'request_id': 'b' * 32, 'operation': 'embed',
            'input': 'Merhaba AiPBX', 'max_tokens': 1, 'deadline_at': int(time.time()) + 30
        }).encode()
        ctx = {'connection': {'adapter': 'runtime', 'model': 'e5-small-multilingual'},
               'model_ref': 'local:e5-small-multilingual', 'config_revision': 'v1'}
        res = g.execute(payload, manager=FakeMgr(), resolver=Mock(return_value=ctx))
        self.assertEqual(res['status'], 'succeeded')
        self.assertEqual(res['embedding'], [0.25, 0.5, 0.75])
        self.assertEqual(res['dimensions'], 3)
        self.assertEqual(res['resolved_model_ref'], 'local:e5-small-multilingual')

    def test_embed_accepts_the_numpy_array_a_local_model_returns(self):
        try:
            import numpy as np
        except ImportError:
            self.skipTest('numpy not installed')

        class FakeMgr:
            def embed(self, model_id, texts):
                return np.array([[0.25, 0.5, 0.75]], dtype=np.float32)
        payload = json.dumps({
            'protocol_version': 1, 'request_id': 'c' * 32, 'operation': 'embed',
            'input': 'Merhaba', 'max_tokens': 1, 'deadline_at': int(time.time()) + 30
        }).encode()
        ctx = {'connection': {'adapter': 'runtime', 'model': 'e5-small-multilingual'},
               'model_ref': 'local:e5-small-multilingual', 'config_revision': 'v1'}
        res = g.execute(payload, manager=FakeMgr(), resolver=Mock(return_value=ctx))
        self.assertEqual(res['status'], 'succeeded', res)
        self.assertEqual(res['embedding'], [0.25, 0.5, 0.75])

    def test_runtime_adapter_passes_remaining_deadline_as_timeout(self):
        seen = []

        class FakeMgr:
            def chat(self, model_id, prompt, max_tokens, timeout=None):
                seen.append((model_id, prompt, max_tokens, timeout))
                return 'OK'

        ctx = {'connection': {'adapter': 'runtime', 'model': 'qwen'}, 'model_ref': 'local:qwen', 'config_revision': 'v1'}
        res = g.execute(self.payload(), manager=FakeMgr(), resolver=Mock(return_value=ctx))
        self.assertEqual(res['status'], 'succeeded')
        self.assertEqual(len(seen), 1)
        self.assertIsNotNone(seen[0][3])
        self.assertGreater(seen[0][3], 0)
        self.assertLessEqual(seen[0][3], 35)

    def test_runtime_adapter_timeout_maps_to_deadline_exceeded(self):
        class TimeoutMgr:
            def chat(self, model_id, prompt, max_tokens, timeout=None):
                raise TimeoutError('timed out')

        ctx = {'connection': {'adapter': 'runtime', 'model': 'qwen'}, 'model_ref': 'local:qwen', 'config_revision': 'v1'}
        res = g.execute(self.payload(), manager=TimeoutMgr(), resolver=Mock(return_value=ctx))
        self.assertEqual(res['status'], 'failed')
        self.assertEqual(res['error']['code'], 'deadline_exceeded')


if __name__ == '__main__':unittest.main()
