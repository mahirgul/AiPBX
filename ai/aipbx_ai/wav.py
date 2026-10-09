"""WAV container for 16-bit mono PCM."""
import struct


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
