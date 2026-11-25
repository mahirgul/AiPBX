"""Client & schema formatters for PbxToolRegistry (Faz 6)."""
from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


def as_openai_tools(schemas: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Converts PbxToolRegistry schemas to OpenAI / Realtime tool definitions."""
    out: list[dict[str, Any]] = []
    for s in schemas:
        if not isinstance(s, dict) or not s.get("name"):
            continue
        out.append({
            "type": "function",
            "name": str(s["name"]),
            "description": str(s.get("description") or ""),
            "parameters": s.get("parameters") or {"type": "object", "properties": {}},
        })
    return out


def format_for_prompt(schemas: list[dict[str, Any]]) -> str:
    """Formats PbxToolRegistry schemas into concise instructions for local LLMs."""
    if not schemas:
        return ""
    lines = ["Available PBX tools (reply with {\"action\":\"tool\",\"tool\":\"<name>\",\"args\":{...}}):"]
    for s in schemas:
        name = str(s.get("name") or "")
        desc = str(s.get("description") or "")
        confirm = " [requires caller confirmation: confirmed=true]" if s.get("confirm") else ""
        props = list(((s.get("parameters") or {}).get("properties") or {}).keys())
        lines.append(f"- {name}({', '.join(props)}): {desc}{confirm}")
    return "\n".join(lines)


class PbxToolsClient:
    """Calls /api/internal_ai_tools.php over loopback HTTP."""

    def __init__(self, url: str = "http://127.0.0.1/api/internal_ai_tools.php", key: str = "", timeout: float = 4.0) -> None:
        self.url = url
        self.key = key
        self.timeout = timeout

    def execute(self, tool: str, args: dict[str, Any], caller: dict[str, Any] | None = None) -> dict[str, Any]:
        target = self.url
        if self.key:
            sep = "&" if "?" in target else "?"
            target = f"{target}{sep}key={urllib.parse.quote(self.key)}"
        payload = json.dumps({"tool": tool, "args": args, "caller": caller or {}}, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            target,
            data=payload,
            method="POST",
            headers={"Content-Type": "application/json", "Accept": "application/json"},
        )
        try:
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open(req, timeout=self.timeout) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                return data if isinstance(data, dict) else {"ok": False, "text": "Invalid response"}
        except (urllib.error.URLError, OSError, json.JSONDecodeError) as e:
            return {"ok": False, "kind": "lookup", "text": f"Tool execution error: {e}"}
