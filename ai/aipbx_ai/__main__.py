"""Entry point: python -m aipbx_ai (aipbx-ai.service).

Environment:
    CREDENTIALS_DIRECTORY  set by systemd (LoadCredential=token:...); the token
                           is read from $CREDENTIALS_DIRECTORY/token
    AIPBX_AI_TOKEN_FILE    token file when not run by systemd
                           (default /etc/aipbx/ai.token)
    AIPBX_AI_PORT          TCP port on 127.0.0.1 (default 8790)
    AIPBX_AI_DATA          data directory (default /var/lib/aipbx-ai)
    HF_HOME                model files (default $AIPBX_AI_DATA/hf)
    XDG_CACHE_HOME         caches (default $AIPBX_AI_DATA/cache)
"""
import logging
import os
import sys
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
    # Before anything imports huggingface_hub: it reads these at import time.
    os.environ.setdefault("HF_HOME", str(data_dir / "hf"))
    os.environ.setdefault("XDG_CACHE_HOME", str(data_dir / "cache"))
    os.environ.setdefault("HF_HUB_DISABLE_TELEMETRY", "1")
    # One line per HTTP request of a download is noise in the journal.
    for name in ("httpx", "httpx2", "httpcore"):
        logging.getLogger(name).setLevel(logging.WARNING)

    try:
        token = read_token()
    except (OSError, ValueError) as e:
        logging.error("cannot read the API token: %s", e)
        return 1
    port = int(os.environ.get("AIPBX_AI_PORT", "8790"))

    from .models import REGISTRY, ModelManager
    from .server import AiServer

    manager = ModelManager(REGISTRY, data_dir)
    server = AiServer((HOST, port), token, manager)
    manager.start()
    logging.info("listening on %s:%d", HOST, port)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
