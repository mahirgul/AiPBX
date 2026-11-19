"""Application configs keep references; credentials are resolved just before use."""
import json
import os
import re
import socket


def validate_reference(cfg, task):
    ref = cfg.get('secret_ref')
    match = re.fullmatch(r'app:([1-9][0-9]{0,6}):(llm|stt|tts):([a-z_]{2,20})', ref or '')
    if not match or match[2] != task or match[3] != cfg.get('provider'):
        raise ValueError('invalid credential reference')
    return {'provider': match[3], 'secret_ref': ref}


def _ask(request, timeout=10):
    """One request to the portal's restricted resolver (credential_broker.py); its JSON answer."""
    path = os.environ.get('AIPBX_AI_RESOLVER_SOCKET', '/run/aipbx-ai-resolver/resolve.sock')
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(timeout)
        conn.connect(path)
        conn.sendall(json.dumps(request, ensure_ascii=False).encode() + b'\n')
        data = bytearray()
        while len(data) <= 65536:
            chunk = conn.recv(4096)
            if not chunk:
                break
            data.extend(chunk)
    return json.loads(data)


def caller_name(number):
    """The shared phonebook's display name, over the local resolver's UTF-8 JSON."""
    if not number:
        return ""
    try:
        result = _ask({'caller_number': number}, timeout=0.75)
        if result.get('ok') and isinstance(result.get('name'), str):
            return result['name']
    except (OSError, ValueError, AttributeError):
        pass
    return ""


def knowledge(app_id, query):
    """The knowledge base excerpts for a conversation lookup; ValueError when unavailable."""
    try:
        result = _ask({'knowledge_app': int(app_id), 'query': str(query)[:300]})
        if not result.get('ok') or not isinstance(result.get('text'), str):
            raise ValueError
        return result['text']
    except (OSError, ValueError, KeyError, TypeError):
        raise ValueError('knowledge base unavailable') from None


def db_value(app_id, query, verified_customer, call_context=None):
    """Query one value. The resolver reloads the application's allowed queries."""
    try:
        result = _ask({'db_app': app_id, 'query_id': query['query_id'], 'params': query['params'],
                       'verified_customer': verified_customer, 'call_context': call_context or {}})
        if result.get('ok') and result.get('status') in ('found', 'not_found', 'verification_required', 'unavailable'):
            answer = {'status': result['status']}
            if result['status'] == 'found':
                answer['value'] = result['value']
            return json.dumps(answer, ensure_ascii=False)
    except (OSError, ValueError, KeyError, TypeError):
        pass
    return '{"status":"unavailable"}'


def local_chat(app_id):
    result = _ask({'local_app': app_id})
    connection = result.get('connection')
    if not result.get('ok') or not isinstance(connection, dict) or connection.get('scope') != 'local':
        raise ValueError('local model unavailable')
    return connection


def resolve(cfg):
    if 'secret_ref' not in cfg:
        return cfg
    try:
        result = _ask({'secret_ref': cfg['secret_ref']})
        if not result.get('ok') or not isinstance(result.get('credential'), dict):
            raise ValueError('credential unavailable')
        credential = result['credential']
        if credential.get('provider') != cfg['provider'] or 'secret_ref' in credential:
            raise ValueError('credential unavailable')
        return credential
    except (OSError, ValueError, KeyError, TypeError):
        raise ValueError('credential unavailable') from None


def reference_config(app_id, config):
    """Upgrade old on-disk configs without retaining secrets or endpoint overrides."""
    out = dict(config)
    if not out.get('language'):
        for name in ('stt_cloud', 'tts_cloud'):
            if isinstance(out.get(name), dict) and out[name].get('lang'):
                out['language'] = out[name]['lang']
                break
    for task in ('llm', 'stt', 'tts'):
        key = task + '_cloud'
        if isinstance(out.get(key), dict) and out[key].get('provider'):
            provider = out[key]['provider']
            out[key] = {'provider': provider, 'secret_ref': f'app:{app_id}:{task}:{provider}'}
    return out
