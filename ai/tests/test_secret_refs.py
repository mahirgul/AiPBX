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
