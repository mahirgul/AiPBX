"""Optional detectors for the call path: Silero VAD (speech or noise) and Smart Turn (is the caller done?).

Both are small ONNX files (2 MB and 9 MB) that run on the CPU through onnxruntime, installed with the
Local AI runtime. They are catalogue entries like any model (kinds "vad" and "turn"), downloaded by the
administrator on demand and pinned by revision and SHA-256. Nothing here is needed for a call: the
callers ask the manager for a ready backend and fall back to the energy detector and the fixed
silence when there is none.

* Silero VAD (MIT), the onnx-community export of snakers4/silero-vad: 8 kHz audio in windows of 256
  samples (32 ms) with 32 samples of the window before it, and a recurrent state of its own per call.
* Smart Turn v3 (BSD-2, Pipecat): the last 8 seconds of the caller's turn at 16 kHz as a Whisper log-mel
  spectrogram; the answer is the probability that the turn is complete. The model was trained on
  16 kHz speech of many languages, Turkish included; telephone audio is 8 kHz widened to 16 kHz, so
  how well it does on our lines is measured, not assumed (docs/local-ai.md).
"""
from .embedding import EmbeddingBackend

SILERO_WINDOW = 256          # samples at 8 kHz (32 ms)
SILERO_CONTEXT = 32          # the end of the previous window, in front of the next one
SMART_TURN_SECONDS = 8
SMART_TURN_RATE = 16000


def _np():
    """numpy comes with the Local AI runtime; the gateway itself starts without it."""
    import numpy
    return numpy


class _OnnxBackend(EmbeddingBackend):
    """The download, checksum and removal of EmbeddingBackend; one model.onnx loaded on the CPU."""

    def load(self):
        import onnxruntime as ort
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = 1      # tiny models: a second thread costs more than it gives
        self.session = ort.InferenceSession(str(self.dir / "model.onnx"), opts, providers=["CPUExecutionProvider"])
        self.np = _np()

    def unload(self):
        self.session = None


class SileroStream:
    """One call's voice activity: feed() takes 8 kHz 16-bit audio of any length, returns the latest probability."""

    def __init__(self, session):
        np = _np()
        self.session = session
        self.state = np.zeros((2, 1, 128), np.float32)
        self.context = np.zeros(SILERO_CONTEXT, np.float32)
        self.pending = np.zeros(0, np.float32)
        self.sr = np.array(8000, dtype=np.int64)
        self.probability = 0.0

    def feed(self, pcm):
        np = _np()
        samples = np.frombuffer(pcm[: len(pcm) - len(pcm) % 2], dtype="<i2").astype(np.float32) / 32768.0
        self.pending = np.concatenate([self.pending, samples])
        while len(self.pending) >= SILERO_WINDOW:
            window, self.pending = self.pending[:SILERO_WINDOW], self.pending[SILERO_WINDOW:]
            frame = np.concatenate([self.context, window])[None, :]
            out, self.state = self.session.run(None, {"input": frame, "state": self.state, "sr": self.sr})
            self.probability = float(out.reshape(-1)[0])
            self.context = window[-SILERO_CONTEXT:]
        return self.probability


class SileroBackend(_OnnxBackend):
    def new_stream(self):
        return SileroStream(self.session)


# ---- Smart Turn: Whisper log-mel features in numpy ------------------------------------------------

_N_FFT, _HOP, _N_MELS = 400, 160, 80


def _hz_to_mel(freq):
    """Slaney scale: linear up to 1 kHz, logarithmic above."""
    np = _np()
    freq = np.atleast_1d(np.asarray(freq, dtype=np.float64))
    mel = 3.0 * freq / 200.0
    high = freq >= 1000.0
    mel[high] = 15.0 + np.log(freq[high] / 1000.0) * (27.0 / np.log(6.4))
    return mel


def _mel_to_hz(mel):
    np = _np()
    mel = np.atleast_1d(np.asarray(mel, dtype=np.float64))
    freq = 200.0 * mel / 3.0
    high = mel >= 15.0
    freq[high] = 1000.0 * np.exp((np.log(6.4) / 27.0) * (mel[high] - 15.0))
    return freq


def _mel_filters():
    """(201 frequency bins, 80 mel bands): triangles, each scaled to unit area (Slaney)."""
    np = _np()
    edges = _mel_to_hz(np.linspace(_hz_to_mel(0.0)[0], _hz_to_mel(SMART_TURN_RATE / 2.0)[0], _N_MELS + 2))
    bins = np.linspace(0, SMART_TURN_RATE // 2, _N_FFT // 2 + 1)
    slopes = edges[None, :] - bins[:, None]
    lower = -slopes[:, :-2] / np.diff(edges)[:-1]
    upper = slopes[:, 2:] / np.diff(edges)[1:]
    filters = np.maximum(0.0, np.minimum(lower, upper))
    return filters * (2.0 / (edges[2: _N_MELS + 2] - edges[:_N_MELS]))[None, :]


_CACHE = {}


def _tables():
    """The mel filters and the periodic Hann window, made once (numpy is loaded on first use)."""
    if not _CACHE:
        _CACHE["filters"] = _mel_filters()
        _CACHE["window"] = _np().hanning(_N_FFT + 1)[:-1]
    return _CACHE["filters"], _CACHE["window"]


def log_mel(audio):
    """Whisper-style log-mel of exactly 8 s of 16 kHz audio (floats): an array of 80 x 800."""
    np = _np()
    filters, window = _tables()
    n = SMART_TURN_SECONDS * SMART_TURN_RATE
    x = np.asarray(audio, dtype=np.float32)
    x = np.pad(x, (n - len(x), 0)) if len(x) < n else x[-n:]          # the turn's end stays at the end
    x = (x - x.mean()) / np.sqrt(x.var() + 1e-7)
    padded = np.pad(x.astype(np.float64), (_N_FFT // 2, _N_FFT // 2), mode="reflect")
    frames = np.lib.stride_tricks.sliding_window_view(padded, _N_FFT)[::_HOP]
    power = np.abs(np.fft.rfft(frames * window, axis=-1)) ** 2          # (frames, 201)
    mel = np.maximum(1e-10, filters.T @ power.T)
    spec = np.log10(mel)[:, :-1]                                       # the last frame is dropped
    spec = np.maximum(spec, spec.max() - 8.0)
    return ((spec + 4.0) / 4.0).astype(np.float32)


def widen(pcm):
    """8 kHz 16-bit audio as floats at 16 kHz (linear interpolation: the line carries nothing above 4 kHz)."""
    np = _np()
    x = np.frombuffer(pcm[: len(pcm) - len(pcm) % 2], dtype="<i2").astype(np.float32) / 32768.0
    if len(x) < 2:
        return x
    return np.interp(np.arange(2 * len(x)) / 2.0, np.arange(len(x)), x).astype(np.float32)


class SmartTurnBackend(_OnnxBackend):
    def complete(self, pcm):
        """Probability (0..1) that the caller's turn in `pcm` (8 kHz 16-bit) is complete."""
        features = log_mel(widen(pcm))[None, :, :]
        out = self.session.run(None, {"input_features": features})
        return float(out[0].reshape(-1)[0])
