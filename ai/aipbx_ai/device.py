"""Where the local models run: the GPU when this server has a usable one, else the CPU.

Decided once per process and never an error: onnxruntime falls back to the CPU by itself
when the CUDA libraries are missing, and the Whisper loader retries on the CPU (see
whisper_backend.load). AIPBX_AI_DEVICE=cpu in the service environment forces the CPU.
"""
import os

CPU = ["CPUExecutionProvider"]
_providers = None


def forced_cpu():
    return os.environ.get("AIPBX_AI_DEVICE", "auto").strip().lower() == "cpu"


def onnx_providers():
    """onnxruntime execution providers, best first: CUDA when its package and a GPU are there."""
    global _providers
    if _providers is None:
        _providers = CPU
        if not forced_cpu():
            try:
                import onnxruntime as ort
                if "CUDAExecutionProvider" in ort.get_available_providers():
                    if hasattr(ort, "preload_dlls"):        # the nvidia-* pip libraries of this venv
                        ort.preload_dlls()
                    _providers = ["CUDAExecutionProvider"] + CPU
            except Exception:  # noqa: BLE001 - no GPU support is not an error
                _providers = CPU
    return list(_providers)


def ct2_device():
    """"cuda" when CTranslate2 sees a GPU, else "cpu"."""
    if forced_cpu():
        return "cpu"
    try:
        import ctranslate2
        return "cuda" if ctranslate2.get_cuda_device_count() > 0 else "cpu"
    except Exception:  # noqa: BLE001
        return "cpu"
