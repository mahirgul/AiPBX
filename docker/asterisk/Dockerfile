# Asterisk, on its own (docker/README.md).
#
# Nothing here is specific to AiPBX: this is Asterisk 22 from Ubuntu 26.04 —
# the same package install.sh installs, so the modules, sound prompts and
# paths are the ones the portal already expects. The portal writes the PBX
# itself (pbx/*.conf, generated from the database by its Sync* classes) into
# the shared /etc/asterisk volume; this image only supplies the seed and the
# daemon.
#
# Why a separate image at all: it is the one tier that must not restart when
# the web tier is updated. Asterisk owns the calls; the portal owns the data.
FROM ubuntu:26.04

ARG ASTERISK_VERSION=22

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC

# asterisk + asterisk-modules: the daemon and the extra modules the portal's
# configuration uses (res_hep for capture, res_fax, res_odbc, chan_websocket).
# Ubuntu 26.04 carries Asterisk 22.5 — the version the portal targets, and the
# same one the portal image installs for its `asterisk -rx` calls.
# unixodbc + odbc-mariadb: CDR and queue_log go to MariaDB through res_odbc.
# tzdata: the call log timestamps are local time, as on a bare host.
# curl/ca-certificates: the portal's dialplan calls back into http://127.0.0.1.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        asterisk asterisk-modules \
        unixodbc odbc-mariadb \
        tzdata ca-certificates curl procps \
    && rm -rf /var/lib/apt/lists/* \
    # A group every image can agree on *numerically*: the shared volumes are
    # written by the portal (www-data) and read by Asterisk. Group names do not
    # cross containers, group ids do.
    && groupadd --gid 2000 aipbx \
    && usermod --append --groups aipbx asterisk \
    # No systemd here: the daemon runs in the foreground, so the package's
    # unit and its socket activation are irrelevant.
    && rm -f /etc/init.d/asterisk

# The seed the portal owns after first boot: the files install.sh copies from
# the repo (docker/asterisk/entrypoint.sh has the list, with the reason each
# one is seeded rather than generated).
COPY asterisk-config/ /opt/aipbx-seed/asterisk/
COPY docker/asterisk/entrypoint.sh /usr/local/sbin/aipbx-asterisk-entrypoint
RUN chmod 0755 /usr/local/sbin/aipbx-asterisk-entrypoint

# Documentation only: the container uses host networking (compose.yaml).
EXPOSE 5060/udp 5060/tcp 5061/tcp 8088/tcp 8089/tcp 5038/tcp

ENTRYPOINT ["/usr/local/sbin/aipbx-asterisk-entrypoint"]
CMD ["asterisk"]
