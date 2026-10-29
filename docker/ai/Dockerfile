# The local AI runtime (docker/README.md) — optional profile `ai`.
#
# This is the one tier whose image is large on purpose: it carries ONNX
# Runtime, Vosk and CTranslate2 so speech-to-text and text-to-speech run on the
# CPU without PyTorch (ai/requirements.txt explains the choice). Keeping it in
# its own container is the point: it can be stopped, given more CPU, or moved
# to a GPU host without touching Asterisk or the portal.
#
# It listens on 127.0.0.1:8790 (HTTP) and 8791 (AudioSocket, which Asterisk's
# dialplan connects to), which is why compose puts it on the host network.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

# ffmpeg: the TTS and STT endpoints accept WAV, and the assistant's media path
# reshapes audio with it. libgomp1: ONNX Runtime's thread pool.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        ffmpeg libgomp1 libsndfile1 ca-certificates tzdata curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/aipbx/ai
COPY ai/ /opt/aipbx/ai/
RUN pip install --no-cache-dir -r requirements.txt

# Models live on their own volume: they are downloaded from the portal
# (AI → models) and must survive a rebuild.
ENV AIPBX_AI_DATA=/var/lib/aipbx-ai \
    AIPBX_AI_PORT=8790 \
    AIPBX_AI_AUDIOSOCKET_PORT=8791

# The daemon binds loopback only; these are documentation.
EXPOSE 8790 8791

CMD ["python", "-m", "aipbx_ai"]
