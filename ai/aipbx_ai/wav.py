"""WAV container for 16-bit mono PCM."""
import io
import struct
import wave

RATE = 16000
MAX_SECONDS = 60


def wav_bytes(pcm, sample_rate):
    """A complete RIFF/WAVE file around little-endian 16-bit mono samples."""
    if len(pcm) % 2:
        raise ValueError("16-bit PCM needs an even number of bytes")
    channels, bits = 1, 16
    block = channels * bits // 8
    header = struct.pack(
        "<4sI4s4sIHHIIHH4sI",
        b"RIFF", 36 + len(pcm), b"WAVE",
        b"fmt ", 16, 1, channels, sample_rate, sample_rate * block, block, bits,
        b"data", len(pcm),
    )
    return header + bytes(pcm)


def pcm16_from_wav(data):
    """(16-bit mono PCM at 16 kHz, seconds) from WAV bytes (8/16/22.05/24/44.1/48 kHz, mono or stereo)."""
    import numpy as np

    try:
        w = wave.open(io.BytesIO(data))
    except (wave.Error, EOFError) as e:
        raise ValueError(f"not a WAV file: {e}") from None
    with w:
        if w.getsampwidth() != 2:
            raise ValueError("WAV must be 16-bit PCM")
        rate, channels, frames = w.getframerate(), w.getnchannels(), w.getnframes()
        if not 8000 <= rate <= 48000 or channels not in (1, 2):
            raise ValueError("unsupported WAV format")
        if frames == 0 or rate == 0:
            raise ValueError("empty WAV audio")
        if frames / rate > MAX_SECONDS:
            raise ValueError(f"audio longer than {MAX_SECONDS} s")
        raw_frames = w.readframes(frames)
    frame_bytes = 2 * channels
    if len(raw_frames) % frame_bytes:
        raw_frames = raw_frames[:-(len(raw_frames) % frame_bytes)]
    if not raw_frames:
        raise ValueError("empty or corrupted WAV audio")
    x = np.frombuffer(raw_frames, dtype="<i2").astype(np.float32) / 32768.0
    if channels == 2:
        x = x.reshape(-1, 2).mean(axis=1)
    if rate != RATE:
        from .engines.piper import resample
        x = resample(x, rate, RATE)
    return (np.clip(x, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes(), frames / rate
