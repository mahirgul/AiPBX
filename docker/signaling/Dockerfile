# HEPv3 capture (docker/README.md).
#
# The daemon is the repo's own `signaling/` package — the same code the
# aipbx-signaling.service unit runs on a bare host — with its one runtime
# dependency (PyMySQL) added. It listens on 127.0.0.1:9061 because Asterisk
# sends res_hep datagrams there, which only works if both are in the same
# network namespace: hence host networking in compose.yaml.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

# tzdata: the event timestamps are local time, like the rest of the portal.
# ca-certificates: nothing is fetched at runtime, but MariaDB TLS in a future
# config should not need an image rebuild to work.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends tzdata ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/aipbx/signaling
COPY signaling/ /opt/aipbx/signaling/
# Only the client library: the daemon itself is standard library otherwise.
RUN pip install --no-cache-dir 'pymysql>=1.1,<2'

# It writes pbx_signaling_events; it never listens on a public port.
EXPOSE 9061/udp

COPY docker/signaling/entrypoint.sh /usr/local/sbin/aipbx-signaling-entrypoint
RUN chmod 0755 /usr/local/sbin/aipbx-signaling-entrypoint

ENTRYPOINT ["/usr/local/sbin/aipbx-signaling-entrypoint"]
CMD ["python", "-m", "aipbx_signaling"]
