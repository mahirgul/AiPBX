"""Entry point: python -m aipbx_ai (aipbx-ai.service).

Environment:
    CREDENTIALS_DIRECTORY  set by systemd (LoadCredential=token:...); the token
                           is read from $CREDENTIALS_DIRECTORY/token
    AIPBX_AI_TOKEN_FILE    token file when not run by systemd
                           (default /etc/aipbx/ai.token)
    AIPBX_AI_PORT          TCP port on 127.0.0.1 (default 8790)
    AIPBX_AI_AUDIOSOCKET_PORT  AudioSocket port on 127.0.0.1 for live calls
                           (default 8791; the portal's dialplan uses 8791)
    AIPBX_AI_DATA          data directory (default /var/lib/aipbx-ai)
"""
import logging
import os
import signal
import sys
import threading
from pathlib import Path

HOST = "127.0.0.1"   # never anything else: the API is for the portal on this server


def read_token():
    creds = os.environ.get("CREDENTIALS_DIRECTORY")
    path = Path(creds) / "token" if creds else Path(os.environ.get("AIPBX_AI_TOKEN_FILE", "/etc/aipbx/ai.token"))
    token = path.read_text(encoding="ascii").strip()
    if len(token) < 32:
        raise ValueError(f"token in {path} is too short")
    return token


def main():
    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(levelname)s %(name)s: %(message)s")
    data_dir = Path(os.environ.get("AIPBX_AI_DATA", "/var/lib/aipbx-ai"))

    try:
        token = read_token()
    except (OSError, ValueError) as e:
        logging.error("cannot read the API token: %s", e)
        return 1
    port = int(os.environ.get("AIPBX_AI_PORT", "8790"))
    audiosocket_port = int(os.environ.get("AIPBX_AI_AUDIOSOCKET_PORT", "8791"))

    from .ari import AriClient, AriRunner, AriSessionManager
    from .audiosocket import AudioSocketServer
    from .call_registry import CallRegistry
    from .custom import Catalog
    from .models import REGISTRY, ModelManager
    from .server import AiServer

    catalog = Catalog(data_dir)
    # Built-in models first; voices the administrator added come from their manifests.
    builtin = {s.id for s in REGISTRY}
    added = [s for s in catalog.load_saved() if s.id not in builtin]
    manager = ModelManager(tuple(REGISTRY) + tuple(added), data_dir)
    calls = CallRegistry(manager, token, data_dir=data_dir)
    server = AiServer((HOST, port), token, manager, catalog, calls=calls)
    audiosocket = AudioSocketServer((HOST, audiosocket_port), server.calls).start()
    ari_runner = _start_ari_if_configured(audiosocket_port, server.calls)
    manager.start()
    logging.info("listening on %s:%d (AudioSocket %s:%d)", HOST, port, HOST, audiosocket_port)
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=server.shutdown, daemon=True).start())
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        if ari_runner is not None:
            ari_runner.close()
        audiosocket.shutdown()
        server.calls.close()
        server.server_close()
    return 0


def _start_ari_if_configured(audiosocket_port: int, calls=None):
    from .ari import AriClient, AriRunner, AriSessionManager
    from .outbound_navigator import OutboundNavigator

    creds = os.environ.get("CREDENTIALS_DIRECTORY")
    ari_path = Path(creds) / "ari_token" if creds else Path(os.environ.get("AIPBX_ARI_PASSWORD_FILE", "/etc/aipbx/ari.token"))
    password = os.environ.get("AIPBX_ARI_PASSWORD", "").strip()
    if not password and ari_path.is_file():
        try:
            password = ari_path.read_text(encoding="ascii").strip()
        except OSError:
            password = ""
    if not password:
        return None
    base_url = os.environ.get("AIPBX_ARI_URL", "http://127.0.0.1:8088/ari")
    username = os.environ.get("AIPBX_ARI_USER", "aipbx_ai")
    client = AriClient(base_url, username, password)
    mgr = AriSessionManager(client, app_name="aipbx-ai", audiosocket_host=HOST, audiosocket_port=audiosocket_port)
    if calls is not None:
        calls.ari_manager = mgr
        calls.on_finish = lambda call_uuid: mgr.release_call(call_uuid, continue_dialplan=True)
        calls.on_dtmf = None
        mgr.on_dtmf = calls.digit_from_ari
        mgr.on_error = calls.note
        nav = OutboundNavigator(mgr, calls)
        mgr.outbound_navigator = nav
        calls.outbound_navigator = nav
    return AriRunner(base_url, username, password, mgr).start()


if __name__ == "__main__":
    sys.exit(main())
