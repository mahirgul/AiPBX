"""Conversation mode of a voice-requests application: several turns with a
cloud language model instead of one request.

Each turn the model gets the administrator's instructions, the requests it
may hand the call to, the lookups it may use and the conversation so far, and
answers with one JSON step:

  {"action": "reply",    "say": "..."}                     speak, then listen again
  {"action": "lookup",   "tool": "<name>", "query": "..."} ask an address of the admin
  {"action": "transfer", "intent": "<id>" | "none", "say": "..."}
  {"action": "end",      "say": "..."}                      goodbye, hang up

Only what the administrator configured can happen: a lookup is one of the
configured addresses (GET, the same contract as announcement lookups, with
the caller's words as q=), a transfer is one of the requests or "none" (the
application's own destination). Anything else in the answer is an error, and
an error, an unclear answer or too many turns hand the call to the
application's destination.
"""

from __future__ import annotations

import json
import re

from . import cloud_llm

MAX_TOOLS = 5
MAX_INSTRUCTIONS = 4000
MAX_TURNS_MIN, MAX_TURNS_MAX, MAX_TURNS_DEFAULT = 2, 12, 6
LOOKUPS_PER_TURN = 2
RESULT_CHARS = 2000          # of a lookup answer, for the model
SAY_CHARS = 400              # of one spoken answer
SUMMARY_CHARS = 300          # of the summary handed over with a transfer
ACTIONS = ("reply", "lookup", "transfer", "end")
_TOOL_NAME = re.compile(r"^[a-z0-9_]{1,30}$")
_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")
LANG_NAMES = {"tr": "Turkish", "de": "German", "en": "English"}


class ConversationError(ValueError):
    pass


def _clean(value, limit):
    if value is None:
        return ""
    if not isinstance(value, str):
        raise ConversationError("must be a string")
    value = _CONTROL.sub(" ", value).strip()
    if len(value) > limit:
        raise ConversationError(f"longer than {limit} characters")
    return value


def validate(data, url_ok):
    """The conversation settings of an application config, or ConversationError.

    url_ok(url) raises ValueError for an address that may not be asked."""
    try:
        instructions = _clean(data.get("instructions"), MAX_INSTRUCTIONS)
    except ConversationError as e:
        raise ConversationError(f"instructions: {e}") from None
    turns = data.get("max_turns", MAX_TURNS_DEFAULT)
    if isinstance(turns, bool) or not isinstance(turns, int) or not MAX_TURNS_MIN <= turns <= MAX_TURNS_MAX:
        raise ConversationError(f"max_turns must be {MAX_TURNS_MIN}-{MAX_TURNS_MAX}")
    tools = data.get("tools") or []
    if not isinstance(tools, list) or len(tools) > MAX_TOOLS:
        raise ConversationError(f"tools: at most {MAX_TOOLS}")
    out, seen = [], set()
    for n, t in enumerate(tools, 1):
        if not isinstance(t, dict):
            raise ConversationError(f"tool {n}: must be an object")
        name = t.get("name")
        if not isinstance(name, str) or not _TOOL_NAME.match(name) or name in seen:
            raise ConversationError(f"tool {n}: name must be 1-30 characters a-z 0-9 _, once")
        seen.add(name)
        try:
            description = _clean(t.get("description"), 300)
            url = _clean(t.get("url"), 500)
        except ConversationError as e:
            raise ConversationError(f"tool {name}: {e}") from None
        if not description:
            raise ConversationError(f"tool {name}: description is empty")
        if not re.match(r"^https?://[^\s]+$", url):
            raise ConversationError(f"tool {name}: url must be http(s)")
        try:
            url_ok(url)
        except ValueError as e:
            raise ConversationError(f"tool {name}: {e}") from None
        out.append({"name": name, "description": description, "url": url})
    return {"instructions": instructions, "max_turns": turns, "tools": out}


def prompt(config, history, lang):
    """The instruction for the next step: rules, requests, lookups, the conversation."""
    requests = "\n".join(
        f'- "{i["id"]}": {i.get("name") or i["id"]}'
        + (f' (e.g. {" | ".join(i["examples"][:3])})' if i.get("examples") else "")
        for i in config["intents"])
    tools = "\n".join(f'- "{t["name"]}": {t["description"]}' for t in config.get("tools", [])) or "(none)"
    lines = []
    for role, text in history:
        lines.append({"caller": "Caller", "assistant": "You", "lookup": "Lookup result",
                      "context": "Before you (another assistant's summary)"}[role] + ": " + text)
    return (
        "You are the voice assistant answering a company's telephone line. A speech recogniser wrote down "
        "what the caller said; it may contain errors, so judge by meaning.\n"
        f"Speak {LANG_NAMES.get(lang, 'Turkish')}, in at most two short sentences, as said aloud on the "
        "phone: no lists, no markdown, no emoji.\n"
        "Use only the company's instructions and lookup results below; never invent facts, prices, "
        "times or promises. When you cannot help, the caller asks for a person, or you are unsure, "
        'transfer with intent "none".\n\n'
        "Company instructions:\n" + (config.get("instructions") or "(none)") + "\n\n"
        "Requests you may transfer the call to:\n" + requests + "\n\n"
        "Lookups you may use (the query is sent to the company's system):\n" + tools + "\n\n"
        "Conversation so far:\n" + "\n".join(lines) + "\n\n"
        "Reply with JSON only, one of:\n"
        '{"action": "reply", "say": "<your answer>"}\n'
        '{"action": "lookup", "tool": "<lookup name>", "query": "<what to look up>"}\n'
        '{"action": "transfer", "intent": "<request id or none>", "say": "<short sentence before the transfer>", '
        '"summary": "<one sentence for whoever takes the call: who, what they want, what you found>"}\n'
        '{"action": "end", "say": "<goodbye>"}  (only when the caller has nothing more to ask)'
    )


def parse_step(raw, config):
    """The model's answer as a checked step dict; CloudLlmError when unusable."""
    match = re.search(r"\{.*\}", raw or "", re.S)
    try:
        data = json.loads(match.group(0)) if match else None
    except ValueError:
        data = None
    if not isinstance(data, dict) or data.get("action") not in ACTIONS:
        raise cloud_llm.CloudLlmError("not a step")
    action = data["action"]
    say = data.get("say") if isinstance(data.get("say"), str) else ""
    step = {"action": action, "say": _CONTROL.sub(" ", say).strip()[:SAY_CHARS]}
    if action == "reply" and not step["say"]:
        raise cloud_llm.CloudLlmError("empty reply")
    if action == "lookup":
        names = {t["name"] for t in config.get("tools", [])}
        if data.get("tool") not in names:
            raise cloud_llm.CloudLlmError("unknown lookup")
        step["tool"] = data["tool"]
        step["query"] = _CONTROL.sub(" ", str(data.get("query") or "")).strip()[:200]
    if action == "transfer":
        intent = data.get("intent")
        if intent in (None, "", "none"):
            step["intent"] = None
        elif intent in {i["id"] for i in config["intents"]}:
            step["intent"] = intent
        else:
            raise cloud_llm.CloudLlmError("unknown request")
        summary = data.get("summary") if isinstance(data.get("summary"), str) else ""
        step["summary"] = _CONTROL.sub(" ", summary).strip()[:SUMMARY_CHARS]
    return step


def next_step(config, history, lang, lookup, post=None):
    """Asks the model until it replies, transfers or ends (lookups in between,
    at most LOOKUPS_PER_TURN). lookup(tool, query) returns the answer text.
    Appends lookups to `history`; returns the final step. CloudLlmError when the
    model cannot be used."""
    for n in range(LOOKUPS_PER_TURN + 1):
        args = (config["llm_cloud"], prompt(config, history, lang))
        raw = cloud_llm.answer_text(*args) if post is None else cloud_llm.answer_text(*args, post=post)
        step = parse_step(raw, config)
        if step["action"] != "lookup":
            return step
        if n == LOOKUPS_PER_TURN:
            break
        try:
            result = lookup(step["tool"], step["query"])
        except ValueError as e:
            result = f"(the lookup failed: {e})"
        history.append(("lookup", f'{step["tool"]}("{step["query"]}") -> {result[:RESULT_CHARS]}'))
    raise cloud_llm.CloudLlmError("too many lookups")
