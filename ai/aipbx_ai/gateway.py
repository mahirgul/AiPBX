"""Version 1 gateway: bounded synchronous execution, one-use credential references.

No provider secrets or caller-supplied endpoints are accepted in the worker request.
The Unix resolver rechecks the current principal, settings and cloud policy.
"""
import hashlib
import http.client
import ipaddress
import json
import logging
import math
import os
import socket
import ssl
import time
import threading
import urllib.parse
from .cloud_llm import _is_forbidden_ip

LOG = logging.getLogger(__name__)

VERSION = 1
OPERATIONS = ('chat',)
MAX_RESPONSE = 1024 * 1024


class GatewayError(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def remaining(deadline):
    seconds = deadline - time.monotonic()
    if seconds <= 0:
        raise GatewayError('deadline_exceeded')
    return seconds


def resolve(request_id, payload_hash, deadline):
    path = os.environ.get('AIPBX_AI_RESOLVER_SOCKET', '/run/aipbx-ai-resolver/resolve.sock')
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(min(10, remaining(deadline)))
        conn.connect(path)
        conn.sendall(json.dumps({'request_id': request_id, 'payload_hash': payload_hash}).encode() + b'\n')
        chunks = bytearray()
        while len(chunks) <= 65536:
            conn.settimeout(min(10, remaining(deadline)))
            chunk = conn.recv(4096)
            if not chunk:
                break
            chunks.extend(chunk)
        if len(chunks) > 65536:
            raise GatewayError('resolver_unavailable')
    data = json.loads(chunks)
    if data.get('ok') is not True:
        raise GatewayError(data.get('error', 'permission_denied'))
    return data


def post_json(url, headers, body, deadline, allow_private=False):
    """Pin the validated DNS address to the actual socket; no redirects/proxy env."""
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ('http', 'https') or not parts.hostname or parts.username or parts.password or parts.fragment:
        raise GatewayError('invalid_endpoint')
    if parts.scheme != 'https' and not allow_private:
        raise GatewayError('invalid_endpoint')
    port = parts.port or (443 if parts.scheme == 'https' else 80)
    addresses = socket.getaddrinfo(parts.hostname, port, type=socket.SOCK_STREAM)
    if not addresses:
        raise GatewayError('provider_unavailable')
    for _, _, _, _, addr in addresses:
        if _is_forbidden_ip(ipaddress.ip_address(addr[0].split('%', 1)[0]),
                            allow_private=allow_private, allow_loopback=allow_private):
            raise GatewayError('invalid_endpoint')
    # Every address was checked above; try them in turn (an unreachable IPv6 first must not fail a dual-stack host).
    sock, last_error = None, None
    for family, kind, proto, _, address in addresses:
        candidate = socket.socket(family, kind, proto)
        try:
            candidate.settimeout(remaining(deadline))
            candidate.connect(address)
            sock = candidate
            break
        except OSError as e:
            candidate.close()
            last_error = e
    if sock is None:
        raise last_error or GatewayError('provider_unavailable')
    client = None
    try:
        if parts.scheme == 'https':
            sock = ssl.create_default_context().wrap_socket(sock, server_hostname=parts.hostname)
        client = http.client.HTTPConnection(parts.hostname, port, timeout=remaining(deadline))
        client.sock = sock
        target = urllib.parse.urlunsplit(('', '', parts.path or '/', parts.query, ''))
        client.request('POST', target, json.dumps(body).encode(),
                       dict(headers, **{'Content-Type': 'application/json', 'User-Agent': 'aipbx-ai'}))
        response = client.getresponse()
        if response.status >= 300:
            codes = {401: 'provider_auth_failed', 403: 'provider_auth_failed', 404: 'model_unavailable',
                     410: 'model_unavailable', 429: 'rate_limited'}
            raise GatewayError(codes.get(response.status, 'provider_unavailable'))
        result = bytearray()
        while len(result) <= MAX_RESPONSE and not response.isclosed():
            sock.settimeout(remaining(deadline))
            chunk = response.read1(min(16384, MAX_RESPONSE + 1 - len(result)))
            if not chunk:
                break
            result.extend(chunk)
        if len(result) > MAX_RESPONSE:
            raise GatewayError('response_too_large')
        remaining(deadline)
        return json.loads(result)
    finally:
        if client:
            client.close()
        sock.close()


def chat(connection, prompt, max_tokens, deadline, post=post_json):
    adapter = connection['adapter']
    key = connection.get('api_key', '')
    if adapter == 'google':
        result = post(connection['url'], {'x-goog-api-key': key},
                      {'contents': [{'parts': [{'text': prompt}]}],
                       'generationConfig': {'temperature': 0, 'maxOutputTokens': max_tokens}}, deadline)
        return result['candidates'][0]['content']['parts'][0]['text'], result.get('usageMetadata', {}).get('totalTokenCount', 0)
    if adapter == 'anthropic':
        result = post(connection['url'], {'x-api-key': key, 'anthropic-version': '2023-06-01'},
                      {'model': connection['model'], 'max_tokens': max_tokens, 'temperature': 0,
                       'messages': [{'role': 'user', 'content': prompt}]}, deadline)
        usage = result.get('usage', {})
        tokens = int(usage.get('input_tokens', 0)) + int(usage.get('output_tokens', 0))
        return result['content'][0]['text'], tokens
    if adapter != 'openai':
        raise GatewayError('capability_unsupported')
    result = post(connection['url'], {'Authorization': 'Bearer ' + key} if key else {},
                  {'model': connection['model'], 'messages': [{'role': 'user', 'content': prompt}],
                   'temperature': 0, 'max_tokens': max_tokens}, deadline, connection.get('allow_private', False))
    return result['choices'][0]['message']['content'], result.get('usage', {}).get('total_tokens', 0)


def _execute(raw, resolver=resolve, runner=chat):
    request_id = ''
    started = time.monotonic()
    try:
        body = json.loads(raw)
        if not isinstance(body, dict):
            raise GatewayError('invalid_request')
        request_id = body.get('request_id', '')
        if not isinstance(request_id, str) or len(request_id) != 32 or any(c not in '0123456789abcdef' for c in request_id):
            raise GatewayError('invalid_request')
        if type(body.get('protocol_version')) is not int or body['protocol_version'] != VERSION:
            raise GatewayError('protocol_mismatch')
        op = body.get('operation')
        if op != 'chat':
            raise GatewayError('capability_unsupported')
        if set(body) != {'protocol_version', 'request_id', 'operation', 'input', 'max_tokens', 'deadline_at'}:
            raise GatewayError('invalid_request')
        prompt, tokens, expires = body['input'], body['max_tokens'], body['deadline_at']
        if not isinstance(prompt, str) or not prompt.strip() or len(prompt.encode()) > 100000:
            raise GatewayError('invalid_request')
        if type(tokens) is not int or not 1 <= tokens <= 2048 or type(expires) is not int:
            raise GatewayError('invalid_request')
        seconds = expires - time.time()
        if seconds <= 0 or seconds > 95:
            raise GatewayError('deadline_exceeded')
        deadline = started + seconds
        context = resolver(request_id, hashlib.sha256(raw).hexdigest(), deadline)
        remaining(deadline)
        answer, usage = runner(context['connection'], prompt, tokens, deadline)
        remaining(deadline)
        if not isinstance(answer, str) or not answer.strip():
            raise GatewayError('empty_response')
        if not isinstance(usage, (int, float)) or not math.isfinite(usage) or usage < 0:
            usage = 0
        return {'protocol_version': VERSION, 'request_id': request_id, 'status': 'succeeded',
                'resolved_model_ref': context['model_ref'], 'config_revision': context['config_revision'],
                'output': answer.strip(), 'usage': {'unit': 'tokens', 'amount': usage, 'source': 'provider'},
                'timings': {'total_ms': round((time.monotonic() - started) * 1000)}}
    except GatewayError as exc:
        code = exc.code
    except (TimeoutError, socket.timeout):
        code = 'deadline_exceeded'
    except Exception as exc:  # API boundary: no backend diagnostics or credentials in responses
        # The log gets the kind of failure (never the request or a key), or nobody can tell why.
        LOG.warning("gateway request failed: %s", type(exc).__name__)
        code = 'provider_unavailable'
    return {'protocol_version': VERSION, 'request_id': request_id, 'status': 'failed',
            'error': {'code': code, 'retryable': False}}


_SLOTS = threading.BoundedSemaphore(4)


def execute(raw, resolver=resolve, runner=chat):
    if not _SLOTS.acquire(blocking=False):
        try:
            request_id = json.loads(raw).get('request_id', '')
        except (ValueError, AttributeError):
            request_id = ''
        return {'protocol_version': VERSION, 'request_id': request_id, 'status': 'failed',
                'error': {'code': 'capacity_exceeded', 'retryable': False}}
    try:
        return _execute(raw, resolver, runner)
    finally:
        _SLOTS.release()
