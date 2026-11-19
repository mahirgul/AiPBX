"""Restricted local resolver: runs as portal user, exposes only issued one-use tickets."""
import os
import socketserver
import subprocess
import threading

SLOTS = threading.BoundedSemaphore(8)


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        if not SLOTS.acquire(blocking=False):
            return
        try:
            self.connection.settimeout(5)
            request = self.rfile.readline(4097)
            if len(request) > 4096 or not request.endswith(b'\n'):
                return
            result = subprocess.run(['/usr/bin/php', '/opt/aipbx/web/bin/ai_gateway_resolve.php'],
                                    input=request, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                    timeout=12, check=False)
            if result.returncode == 0 and len(result.stdout) <= 65536:
                self.wfile.write(result.stdout)
        except (OSError, subprocess.TimeoutExpired):
            pass
        finally:
            SLOTS.release()


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    request_queue_size = 8


def main():
    path = '/run/aipbx-ai-resolver/resolve.sock'
    if os.path.exists(path):
        os.unlink(path)
    with Server(path, Handler) as server:
        os.chmod(path, 0o660)
        server.serve_forever()


if __name__ == '__main__':
    main()
