"""Hugging Face downloads: only https://huggingface.co (and its HTTPS CDN redirects) is ever contacted."""
import urllib.error
import urllib.parse
import urllib.request

HUB = "https://huggingface.co"
HUB_HOST = "huggingface.co"
_CDN_SUFFIXES = (".huggingface.co", ".hf.co")


class HfError(Exception):
    pass


class HfSecurityError(HfError):
    pass


def _is_allowed_url(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https":
        return False
    host = (parsed.hostname or "").lower()
    if host == HUB_HOST:
        return True
    return any(host.endswith(suf) for suf in _CDN_SUFFIXES)


class _SafeRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        resolved = urllib.parse.urljoin(req.full_url, newurl)
        if not _is_allowed_url(resolved):
            raise HfSecurityError(f"redirect to untrusted host rejected: {resolved}")
        new_req = super().redirect_request(req, fp, code, msg, headers, resolved)
        return new_req


_OPENER = urllib.request.build_opener(_SafeRedirectHandler())


def default_fetch(url, timeout=30):
    if not _is_allowed_url(url):
        raise HfSecurityError(f"only {HUB} URLs are allowed")
    headers = {"User-Agent": "aipbx-ai"}
    req = urllib.request.Request(url, headers=headers)
    try:
        with _OPENER.open(req, timeout=timeout) as resp:
            return resp.read()
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise HfError("Hugging Face rejected the request (gated or private repo)") from None
        if e.code == 404:
            raise HfError("Hugging Face repository or revision not found") from None
        raise HfError(f"Hugging Face HTTP {e.code}") from None
    except HfError:
        raise
    except Exception as e:  # noqa: BLE001
        raise HfError(f"Hugging Face request failed: {e}") from None


def open_download(url):
    if not _is_allowed_url(url):
        raise HfSecurityError("untrusted model download URL")
    headers = {"User-Agent": "aipbx-ai"}
    return _OPENER.open(urllib.request.Request(url, headers=headers), timeout=60)
