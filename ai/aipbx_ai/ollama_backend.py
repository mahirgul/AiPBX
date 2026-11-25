"""Local-only Ollama runtime for verified HF GGUF and safetensors models."""
import http.client
import json
from .hf import CustomWeightsBackend, sha256_file

HOST, PORT = "127.0.0.1", 11434


def request(method, path, body=None, file=None, timeout=600):
    conn = http.client.HTTPConnection(HOST, PORT, timeout=timeout)
    try:
        if file is not None:
            # Stream multi-GB weights; never read them into the Python heap.
            with open(file, "rb") as stream:
                conn.request(method, path, body=stream, headers={
                    "Content-Type": "application/octet-stream", "Content-Length": str(file.stat().st_size)})
        else:
            data = json.dumps(body).encode() if body is not None else None
            conn.request(method, path, body=data, headers={"Content-Type": "application/json"})
        resp = conn.getresponse()
        raw = resp.read(2 * 1024 * 1024)
        if method == "HEAD" and resp.status in (200, 404):
            return resp.status == 200
        if resp.status >= 400:
            raise RuntimeError(f"Ollama HTTP {resp.status}; check that Ollama is installed and supports this model")
        result = json.loads(raw) if raw.strip() else {}
        if result.get("error"):
            raise RuntimeError("Ollama could not load or run this model")
        return result
    except TimeoutError:
        raise
    except OSError:
        raise RuntimeError("Local Ollama is unavailable; install or start it in AI Settings > Local models") from None
    finally:
        conn.close()


class OllamaBackend(CustomWeightsBackend):
    def __init__(self, spec, data_dir):
        super().__init__(spec, data_dir)
        self.name = "aipbx-" + spec.id
        self.loaded = False

    def load(self):
        files = {}
        for name, info in self._files().items():
            path = self.dir / name
            if sha256_file(path) != info["sha256"]:
                raise RuntimeError(f"{name}: checksum mismatch; download the model again")
            digest = "sha256:" + info["sha256"]
            if not request("HEAD", "/api/blobs/" + digest):
                request("POST", "/api/blobs/" + digest, file=path)
            files[name] = digest
        request("POST", "/api/create", {"model": self.name, "files": files, "stream": False})
        request("POST", "/api/generate", {"model": self.name, "prompt": "", "stream": False, "keep_alive": -1})
        self.loaded = True

    def chat(self, prompt, max_tokens=300, timeout=600, num_ctx=None):
        if not self.loaded:
            raise RuntimeError("model not loaded")
        reply = request("POST", "/api/chat", {
            "model": self.name, "messages": [{"role": "user", "content": prompt}],
            "stream": False, "keep_alive": -1, "think": False,   # thinking would eat the token budget
            "options": {"num_predict": max_tokens, "temperature": 0, **({"num_ctx": num_ctx} if num_ctx else {})}},
            timeout=timeout)
        return str((reply.get("message") or {}).get("content") or "")

    def unload(self):
        if self.loaded:
            try:
                request("POST", "/api/generate", {"model": self.name, "keep_alive": 0, "stream": False})
            finally:
                self.loaded = False

    def delete_files(self):
        try:
            self.unload()
            request("DELETE", "/api/delete", {"model": self.name})
        except RuntimeError:
            pass
        super().delete_files()
