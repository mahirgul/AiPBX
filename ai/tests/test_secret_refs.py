import json
import os
import sys
import tempfile
import threading
import socketserver
import unittest
from unittest.mock import patch
from pathlib import Path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))
from aipbx_ai.secret_refs import reference_config, resolve, validate_reference
from aipbx_ai.voice_requests import AppStore


class SecretReferencesTest(unittest.TestCase):
    def test_scoped_reference_rejects_provider_and_task_substitution(self):
        for cfg in [{'provider':'nvidia','secret_ref':'app:1:llm:openai'}, {'provider':'nvidia','secret_ref':'app:1:tts:nvidia'}]:
            with self.assertRaises(ValueError):validate_reference(cfg,'llm')

    def test_no_secret_or_endpoint_persisted_in_converted_config(self):
        cfg={'llm_cloud':{'provider':'nvidia','api_key':'secret','base_url':'https://private.example'}}
        out=reference_config(7,cfg)
        self.assertEqual(out['llm_cloud'],{'provider':'nvidia','secret_ref':'app:7:llm:nvidia'})
        self.assertNotIn('secret',json.dumps(out).replace('secret_ref',''))
        self.assertEqual(cfg['llm_cloud']['api_key'],'secret')

    def test_account_settings_stay_beside_the_reference(self):
        cfg = {'llm_cloud': {'provider': 'openai', 'api_key': 'secret', 'model': 'gpt-4.1', 'temperature': 0.3,
                             'max_tokens': 200, 'base_url': 'https://private.example'},
               'tts_cloud': {'provider': 'openai_compat', 'key': 'x', 'voice': 'nova', 'format': 'wav',
                             'base_url': 'http://10.0.0.5:8000/v1'}}
        out = reference_config(7, cfg)
        self.assertEqual(out['llm_cloud'], {'provider': 'openai', 'secret_ref': 'app:7:llm:openai', 'model': 'gpt-4.1',
                                            'temperature': 0.3, 'max_tokens': 200})       # no address of its own: dropped
        self.assertEqual(out['tts_cloud']['base_url'], 'http://10.0.0.5:8000/v1')
        self.assertEqual((out['tts_cloud']['voice'], out['tts_cloud']['format']), ('nova', 'wav'))

    def test_resolved_credential_takes_the_account_settings(self):
        state = {'ok': True, 'credential': {'provider': 'openai', 'api_key': 'first', 'model': 'old'}}
        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                self.rfile.readline()
                self.wfile.write(json.dumps(state).encode())
        with tempfile.TemporaryDirectory() as tmp:
            path = tmp + '/resolver.sock'
            server = socketserver.UnixStreamServer(path, Handler)
            threading.Thread(target=server.serve_forever, daemon=True).start()
            try:
                with patch.dict(os.environ, {'AIPBX_AI_RESOLVER_SOCKET': path}):
                    got = resolve({'provider': 'openai', 'secret_ref': 'app:7:llm:openai', 'model': 'gpt-4.1'})
            finally:
                server.shutdown(); server.server_close()
        self.assertEqual((got['api_key'], got['model']), ('first', 'gpt-4.1'))

    def test_realtime_secret_ref_and_settings_preserved(self):
        cfg = {'provider': 'google_ai', 'secret_ref': 'app:7:realtime:google_ai'}
        self.assertEqual(validate_reference(cfg, 'realtime'), cfg)
        with self.assertRaises(ValueError):
            validate_reference({'provider': 'google_ai', 'secret_ref': 'app:7:llm:google_ai'}, 'realtime')
        upgraded = reference_config(7, {
            'realtime_cloud': {
                'provider': 'google_ai', 'api_key': 'secret', 'model': 'gemini-3.8-live',
                'voice': 'coral', 'lang': 'tr', 'vad': {'threshold': 0.5, 'silence_ms': 500},
                'noise_reduction': 'off', 'ws_url': 'wss://blocked.example/ws',
            }
        })
        rt = upgraded['realtime_cloud']
        self.assertEqual(rt['secret_ref'], 'app:7:realtime:google_ai')
        self.assertNotIn('api_key', rt)
        self.assertNotIn('ws_url', rt)
        self.assertEqual((rt['model'], rt['voice'], rt['lang'], rt['vad'], rt['noise_reduction']),
                         ('gemini-3.8-live', 'coral', 'tr', {'threshold': 0.5, 'silence_ms': 500}, 'off'))

    def test_each_invocation_resolves_current_credential_and_revocation(self):
        state={'ok':True,'credential':{'provider':'nvidia','api_key':'first'}}
        seen=[]
        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                seen.append(json.loads(self.rfile.readline()))
                self.wfile.write(json.dumps(state).encode())
        with tempfile.TemporaryDirectory() as tmp:
            path=tmp+'/resolver.sock'
            server=socketserver.UnixStreamServer(path,Handler)
            t=threading.Thread(target=server.serve_forever,daemon=True);t.start()
            cfg={'provider':'nvidia','secret_ref':'app:7:llm:nvidia'}
            try:
                with patch.dict(os.environ,{'AIPBX_AI_RESOLVER_SOCKET':path}):
                    self.assertEqual(resolve(cfg)['api_key'],'first')
                    state['credential']['api_key']='second'
                    self.assertEqual(resolve(cfg)['api_key'],'second')
                    state['ok']=False
                    with self.assertRaises(ValueError):resolve(cfg)
                self.assertEqual(len(seen),3)
                self.assertNotIn('api_key',json.dumps(seen))
            finally:server.shutdown();server.server_close();t.join()


if __name__=='__main__':unittest.main()
