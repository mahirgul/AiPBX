"""What a call's start request carries and how its text is made: the dialplan key, the form fields, the
"{value}" template, and the lookup that fills it. Nothing here knows a call exists.
"""
import logging
import hashlib
import hmac
import json
import math
import re
import threading
import unicodedata
import urllib.request
from .cloud_llm import assert_public_url


log = logging.getLogger("aipbx_ai.calls")


DIALPLAN_KEY_MSG = b"aipbx-dialplan"


MAX_TEXT = 2000


MAX_FILLED = 4000            # rendered text (template plus lookup values)


MAX_VALUE = 200              # one lookup value


MAX_URL = 500


MAX_ID_FIELD = 40


MAX_UNIQUEID = 64


APP_MAX = 1000000


MAX_PER_APP_DEFAULT, MAX_PER_APP_LIMIT = 4, 50


SPEED_MIN, SPEED_MAX = 0.5, 2.0


LOOKUP_TIMEOUT = 2.0


LOOKUP_MAX_BYTES = 64 * 1024


ALLOW_PRIVATE_LOOKUP = False


_PLACEHOLDER = re.compile(r"\{([A-Za-z0-9_]{1,40})\}")


_KEY = re.compile(r"^[A-Za-z0-9_]{1,40}$")


_ID_FIELD = re.compile(r"^[0-9+*#A-Za-z_.-]*$")


_UNIQUEID = re.compile(r"^[0-9A-Za-z_.-]*$")


_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")


_FIELDS = ("app", "key", "model", "speed", "max", "caller", "did", "uniqueid", "text", "lookup", "fallback", "lang")


def dialplan_key(token):
    """The key the dialplan sends: hex HMAC-SHA256(token, b"aipbx-dialplan")."""
    token = token.encode() if isinstance(token, str) else bytes(token)
    return hmac.new(token, DIALPLAN_KEY_MSG, hashlib.sha256).hexdigest()


class Refused(Exception):
    """The call is not started; the message goes to the journal."""


def render(template, values):
    """Fills {name} placeholders; returns (text, missing) with missing ones removed."""
    missing = []

    def fill(m):
        name = m.group(1)
        if name in values:
            return values[name]
        missing.append(name)
        return ""

    text = _PLACEHOLDER.sub(fill, template)
    text = re.sub(r"[ \t]+([,.;:!?])", r"\1", text)   # "borcunuz , ..." after a removed value
    text = re.sub(r"\s+", " ", text).strip()
    return text, missing


def display_name(text):
    """Preserve letters and accents in a phonebook name without control characters."""
    name = unicodedata.normalize("NFC", " ".join(text.split()))
    name = "".join(c for c in name if unicodedata.category(c)[0] in "LMN" or c in " .'-’‐‑–")
    return name[:150].strip() if any(c.isalpha() for c in name) else ""


def clean_values(data):
    """Flat {name: value} of a lookup answer: strings and numbers only."""
    values = {}
    for key, value in data.items():
        if not isinstance(key, str) or not _KEY.match(key):
            continue
        if isinstance(value, bool) or value is None:
            continue
        if isinstance(value, int):
            text = str(value)
        elif isinstance(value, float):
            if not math.isfinite(value):
                continue
            text = repr(value) if value != int(value) else str(int(value))
        elif isinstance(value, str):
            text = _CONTROL.sub(" ", value).strip()
        else:
            continue          # nested objects and lists are ignored
        values[key] = text[:MAX_VALUE]
    return values


def lookup_url(url, caller, did, app):
    parts = urllib.parse.urlsplit(url)
    query = [(k, v) for k, v in urllib.parse.parse_qsl(parts.query, keep_blank_values=True)
             if k not in ("caller", "did", "app")]
    query += [("caller", caller), ("did", did), ("app", str(app))]
    return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path or "/",
                                    urllib.parse.urlencode(query), ""))


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


_OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect)


def fetch_values(url, timeout=LOOKUP_TIMEOUT):
    """GETs the lookup URL; returns the values or raises ValueError (hard time limit)."""
    result = {}

    def run():
        try:
            assert_public_url(url, resolve=True, allow_private=ALLOW_PRIVATE_LOOKUP, allow_loopback=ALLOW_PRIVATE_LOOKUP)
            req = urllib.request.Request(url, headers={"Accept": "application/json",
                                                       "User-Agent": "aipbx-ai"})
            with _OPENER.open(req, timeout=timeout) as resp:
                if resp.status != 200:
                    raise ValueError(f"HTTP {resp.status}")
                raw = resp.read(LOOKUP_MAX_BYTES + 1)
            if len(raw) > LOOKUP_MAX_BYTES:
                raise ValueError("answer larger than 64 KB")
            data = json.loads(raw.decode("utf-8"))
            if not isinstance(data, dict):
                raise ValueError("answer is not a JSON object")
            result["values"] = clean_values(data)
        except urllib.error.HTTPError as e:
            result["error"] = f"HTTP {e.code}"
            e.close()
        except (UnicodeDecodeError, json.JSONDecodeError):
            result["error"] = "answer is not JSON"
        except Exception as e:  # noqa: BLE001 - any failure means "use the fallback"
            result["error"] = f"{type(e).__name__}: {e}"[:200]

    t = threading.Thread(target=run, name="call-lookup", daemon=True)
    t.start()
    t.join(timeout)
    if t.is_alive():
        raise ValueError("timed out")
    if "error" in result:
        raise ValueError(result["error"])
    return result.get("values", {})


def parse_form(raw):
    """The urlencoded body as {name: value}; Refused on anything odd."""
    try:
        body = raw.decode("ascii")
    except UnicodeDecodeError:
        raise Refused("body is not URL-encoded") from None
    try:
        pairs = urllib.parse.parse_qsl(body, keep_blank_values=True, strict_parsing=False,
                                       encoding="utf-8", errors="strict", max_num_fields=30)
    except (ValueError, UnicodeDecodeError):
        raise Refused("invalid form body") from None
    form = {}
    for k, v in pairs:
        if k in form:
            raise Refused(f"field {k} given twice")
        form[k] = v
    return form


def _int_field(form, name, lo, hi, default=None):
    raw = form.get(name, "").strip()
    if raw == "" and default is not None:
        return default
    if not raw.isdigit() or len(raw) > 7:
        raise Refused(f"invalid {name}")
    value = int(raw)
    if not lo <= value <= hi:
        raise Refused(f"{name} out of range")
    return value


def _speed(form):
    raw = form.get("speed", "").strip()
    if raw == "":
        return 1.0
    if not re.match(r"^[0-9]{1,2}(\.[0-9]{1,4})?$", raw):
        raise Refused("invalid speed")
    value = float(raw)
    if not SPEED_MIN <= value <= SPEED_MAX:
        raise Refused("speed out of range")
    return value


def _id_field(form, name, pattern, limit):
    """Caller number, DID, uniqueid: an odd value is dropped, not a reason to refuse."""
    value = form.get(name, "").strip()
    if len(value) > limit or not pattern.match(value):
        log.info("call: ignoring invalid %s", name)
        return ""
    return value


def _text(form, name, required):
    value = form.get(name, "")
    value = _CONTROL.sub(" ", value).strip()
    if required and not value:
        raise Refused(f"{name} is empty")
    if len(value) > MAX_TEXT:
        raise Refused(f"{name} longer than {MAX_TEXT} characters")
    return value


def _lookup(form):
    url = form.get("lookup", "").strip()
    if not url:
        return ""
    if len(url) > MAX_URL or re.search(r"[\s\x00-\x1f\x7f]", url):
        raise Refused("invalid lookup URL")
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise Refused("lookup must be an http(s) URL")
    try:
        assert_public_url(url, resolve=True, allow_private=ALLOW_PRIVATE_LOOKUP, allow_loopback=ALLOW_PRIVATE_LOOKUP)
    except ValueError as e:
        raise Refused(str(e)) from None
    return url
