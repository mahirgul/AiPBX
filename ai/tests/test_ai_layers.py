"""Layer and cycle guard for the Python AI service (see AGENTS.md, "Architectural Coupling")."""

import ast
import pathlib
import unittest

AI_PKG = pathlib.Path(__file__).resolve().parents[1] / "aipbx_ai"

# Strictly layered dependency map: a module may only import modules at a strictly lower layer.
LAYERS = {
    "providers": 0,
    "wav": 0,
    "langs": 0,
    "calllog": 0,
    "call_media": 0,
    "ws_reader": 0,
    "pbx_tools": 0,
    "farewell": 0,
    "intents": 0,
    "deepgram_stream": 0,
    "device": 0,
    "hf": 0,
    "sysinfo": 0,
    "textnorm": 0,
    "credential_broker": 0,
    "kokoro_backend": 0,
    "secret_refs": 1,
    "call_session": 1,
    "ema_engine": 1,
    "onnx_models": 1,
    "piper_backend": 1,
    "ari": 1,
    "cloud_llm": 2,
    "ema": 2,
    "cloud_stt": 3,
    "conversation": 3,
    "call_text": 3,
    "gateway": 3,
    "models": 3,
    "cloud_tts": 4,
    "custom": 4,
    "call_tools": 4,
    "voice_requests": 5,
    "call_turn": 6,
    "realtime": 6,
    "call_registry": 6,
    "outbound_navigator": 6,
    "calls": 7,
    "audiosocket": 8,
    "server": 8,
    "__main__": 9,
}


def _module_imports() -> dict[str, set[str]]:
    modules = {p.stem: p for p in AI_PKG.glob("*.py") if p.stem != "__init__"}
    mod_names = set(modules)
    graph: dict[str, set[str]] = {}
    for name, path in sorted(modules.items()):
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        deps: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.ImportFrom) and node.level >= 1:
                if node.module:
                    top = node.module.split(".")[0]
                    if top in mod_names:
                        deps.add(top)
                else:
                    for alias in node.names:
                        if alias.name in mod_names:
                            deps.add(alias.name)
            elif isinstance(node, ast.ImportFrom) and node.module and node.module.startswith("aipbx_ai."):
                top = node.module.split(".")[1]
                if top in mod_names:
                    deps.add(top)
            elif isinstance(node, ast.Import):
                for alias in node.names:
                    if alias.name.startswith("aipbx_ai."):
                        top = alias.name.split(".")[1]
                        if top in mod_names:
                            deps.add(top)
        deps.discard(name)
        graph[name] = deps
    return graph


class AiLayersTests(unittest.TestCase):
    def test_no_import_cycles(self) -> None:
        graph = _module_imports()
        state: dict[str, int] = {}  # 0=unvisited, 1=visiting, 2=done

        def visit(node: str, trail: list[str]) -> None:
            st = state.get(node, 0)
            if st == 2:
                return
            if st == 1:
                cycle = trail[trail.index(node) :] + [node]
                self.fail("Circular import in aipbx_ai: " + " -> ".join(cycle))
            state[node] = 1
            trail.append(node)
            for dep in sorted(graph.get(node, ())):
                visit(dep, trail)
            trail.pop()
            state[node] = 2

        for mod in sorted(graph):
            visit(mod, [])

    def test_call_and_provider_layers_are_lower_triangular(self) -> None:
        graph = _module_imports()
        for mod, deps in sorted(graph.items()):
            self.assertIn(mod, LAYERS, f"New module {mod}.py must declare its architectural layer in test_ai_layers.py")
            mod_layer = LAYERS[mod]
            for dep in sorted(deps):
                self.assertIn(dep, LAYERS)
                self.assertLess(
                    LAYERS[dep],
                    mod_layer,
                    f"{mod} (layer {mod_layer}) imports {dep} (layer {LAYERS[dep]}) — violates lower-triangular layering",
                )


if __name__ == "__main__":
    unittest.main()
