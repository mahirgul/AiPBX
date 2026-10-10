"""aipbx-ai — AI models that run on the PBX itself (roadmap 5, AI -> Local models).

Audio and text never leave the server: the models are downloaded once (from
AiPBX's own model releases, checked by SHA-256) and then run locally on the
CPU with ONNX Runtime. The first model is
EMA Lightning, a Turkish text-to-speech model (Apache-2.0); further models
(e.g. EmbeddingGemma 2 for audio/text embeddings) are new entries in
``models.REGISTRY`` with their own backend class.

Layout on a PBX (written by install.sh and conf/sbin/aipbx-ai-setup):

    /opt/aipbx-ai/app          this package (copied from the repo's ai/)
    /opt/aipbx-ai/venv         Python venv with ONNX Runtime, numpy and the model
                               packages; created only by `aipbx-ai-setup install`
    /var/lib/aipbx-ai          home of the aipbx-ai user: downloaded models
                               (models/<id>/), runtime.json
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
    POST /v1/stt?model=<id>            audio/wav -> {"text", ...}
    GET  /v1/calls                     live and recently finished AI calls
    GET  /v1/calls/{uuid}              result of a voice_requests call (JSON;
                                       bearer token or ?key=<dialplan key>)
    GET  /v1/calls/{uuid}/audio        the caller's utterance (audio/wav; same auth)
    GET  /v1/calls/{uuid}/result?key=  for the dialplan: intent id, "none" or empty
                                       (text/plain, dialplan key, no bearer token)
    GET  /v1/apps                      pushed application configs {"apps": [...]}
    PUT  /v1/apps/{id}                 push a voice_requests config (validated, kept
                                       in $AIPBX_AI_DATA/apps/{id}.json)
    GET  /v1/apps/{id}, DELETE /v1/apps/{id}

Live calls (calls.py): the dialplan calls POST /v1/calls/start with CURL()
(urlencoded, no bearer token: a dialplan key derived from the token instead)
and gets the call's UUID or an empty body; AudioSocket() then connects to
127.0.0.1:8791 and the service plays the prepared audio in real time.

Model states: absent -> downloading -> loading -> ready; "installed" means the
files are on disk but not loaded; "error" keeps the last failure. On start the
service loads every installed model in the background.

Run the tests without the model runtime: ``python3 -m unittest discover ai/tests``.
See docs/local-ai.md for the whole picture.
"""

__version__ = "1"
