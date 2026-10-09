"""aipbx-ai — AI models that run on the PBX itself (roadmap 5, AI -> Local models).

Audio and text never leave the server: the models are downloaded once from
their public repository and then run locally on the CPU. The first model is
EMA Lightning, a Turkish text-to-speech model (Apache-2.0); further models
(e.g. EmbeddingGemma 2 for audio/text embeddings) are new entries in
``models.REGISTRY`` with their own backend class.

Layout on a PBX (written by install.sh and conf/sbin/aipbx-ai-setup):

    /opt/aipbx-ai/app          this package (copied from the repo's ai/)
    /opt/aipbx-ai/venv         Python venv with torch (CPU) and the model
                               packages; created only by `aipbx-ai-setup install`
    /var/lib/aipbx-ai          home of the aipbx-ai user: downloaded models
                               (hf/), caches (cache/), runtime.json
    /etc/aipbx/ai.token        shared secret of the portal and this service
    aipbx-ai.service           systemd unit, 127.0.0.1:8790 only

HTTP API (JSON; errors are 4xx/5xx with {"error": "..."}); every request needs
``Authorization: Bearer <token>`` and is refused when it carries
X-Forwarded-For or Forwarded:

    GET  /v1/health                    service, CPU, RAM and process figures
    GET  /v1/models                    the registry with each model's state
    POST /v1/models/{id}/install       {"accept_license": true} -> 202
    POST /v1/models/{id}/remove        unload and delete the model's files
    POST /v1/models/{id}/benchmark     speed test (model must be ready)
    POST /v1/tts                       {"model","text","speed","sample_rate"}
                                       -> audio/wav, 16-bit mono PCM

Model states: absent -> downloading -> loading -> ready; "installed" means the
files are on disk but not loaded; "error" keeps the last failure. On start the
service loads every installed model in the background.

Run the tests without torch: ``python3 -m unittest discover ai/tests``.
See docs/local-ai.md for the whole picture.
"""

__version__ = "1"
