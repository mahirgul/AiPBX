"""Entry point: python -m aipbx_ai (aipbx-ai.service).

Environment:
    CREDENTIALS_DIRECTORY  set by systemd (LoadCredential=token:...); the token
                           is read from $CREDENTIALS_DIRECTORY/token
    AIPBX_AI_TOKEN_FILE    token file when not run by systemd
                           (default /etc/aipbx/ai.token)
    AIPBX_AI_PORT          TCP port on 127.0.0.1 (default 8790)
    AIPBX_AI_DATA          data directory (default /var/lib/aipbx-ai)
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

    try:
        token = read_token()
    except (OSError, ValueError) as e:
        logging.error("cannot read the API token: %s", e)
        return 1
    port = int(os.environ.get("AIPBX_AI_PORT", "8790"))

    from .custom import Catalog
    from .models import REGISTRY, ModelManager
    from .server import AiServer

    catalog = Catalog(data_dir)
    # Built-in models first; voices the administrator added come from their manifests.
    builtin = {s.id for s in REGISTRY}
    added = [s for s in catalog.load_saved() if s.id not in builtin]
    manager = ModelManager(tuple(REGISTRY) + tuple(added), data_dir)
    server = AiServer((HOST, port), token, manager, catalog)
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
