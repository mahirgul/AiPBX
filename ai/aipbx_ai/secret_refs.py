"""Application configs keep references; credentials are resolved just before use."""
import json
import os
import re
import socket


# The per-account settings that travel beside a credential reference (validated by cloud_llm/stt/tts).
SETTINGS = ('model', 'base_url', 'temperature', 'max_tokens', 'voice', 'format')
# Set by a call for its own language, over what the resolver returns.
CALL_KEYS = ('lang', 'locale', 'lang_name')


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


def caller_info(number):
    """(display_name, customer_id) over the local resolver's UTF-8 JSON."""
    if not number:
        return "", ""
    try:
        result = _ask({'caller_number': number}, timeout=0.75)
        if result.get('ok'):
            name = result['name'] if isinstance(result.get('name'), str) else ""
            cust = result['customer_id'] if isinstance(result.get('customer_id'), str) else ""
            return name, cust
    except (OSError, ValueError, AttributeError):
        pass
    return "", ""


def caller_name(number):
    """The shared phonebook's display name, over the local resolver's UTF-8 JSON."""
    return caller_info(number)[0]


def knowledge(app_id, query, source_id=None):
    """The excerpts of one knowledge source (or of all when source_id is None) for a conversation lookup;
    ValueError when unavailable."""
    request = {'knowledge_app': int(app_id), 'query': str(query)[:300]}
    if source_id is not None:
        request['source_id'] = int(source_id)
    try:
        result = _ask(request)
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
                if isinstance(result.get('data'), dict):
                    answer['data'] = result['data']
            return json.dumps(answer, ensure_ascii=False)
    except (OSError, ValueError, KeyError, TypeError):
        pass
    return '{"status":"unavailable"}'


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
        return {**credential, **{k: cfg[k] for k in SETTINGS + CALL_KEYS if cfg.get(k) is not None}}
    except (OSError, ValueError, KeyError, TypeError):
        raise ValueError('credential unavailable') from None


def reference_config(app_id, config):
    """Upgrade old on-disk configs without retaining secrets; the account settings (model, voice ...) stay."""
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
            kept = {k: out[key][k] for k in SETTINGS if out[key].get(k) is not None}
            if provider not in ('openai_compat', 'qwen'):      # only these have an address of their own
                kept.pop('base_url', None)
            out[key] = {**kept, 'provider': provider, 'secret_ref': f'app:{app_id}:{task}:{provider}'}
    return out
