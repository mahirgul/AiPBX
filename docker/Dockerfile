# AiPBX in a container (docker/README.md).
#
# AiPBX is a full host install, not a single process: install.sh sets up
# Asterisk, MariaDB, Apache behind an nginx 443 multiplexer, coturn, Postfix,
# mDNS, dnsmasq for phones, a dozen systemd units and a firewall. The only way
# to run that unchanged is to give it a host — so this image is Ubuntu 26.04
# (the release install.sh supports) with systemd as PID 1, and the real
# install.sh runs once on first boot.
#
# The image itself carries no AiPBX code and no secrets: the source is cloned
# into a volume on first boot and the installer generates every password for
# that deployment. Nothing here is shared between two installs.
FROM ubuntu:26.04

ENV DEBIAN_FRONTEND=noninteractive

# systemd/systemd-sysv: PID 1 and the units install.sh writes.
# sudo: install.sh writes /etc/sudoers.d and the portal calls things through it.
# git/rsync: the source checkout and the file copies install.sh and aipbx-update do.
# ca-certificates: HTTPS clones and Let's Encrypt.
# tcpdump: diagnostics for SIP/RTP (the portal offers capture); install.sh installs
#          the rest of what it needs itself.
# cron: the daemon behind /etc/cron.d/aipbx — the 02:30 backup, phone discovery
#       every five minutes, retention. It has to be in the image, not just in
#       install.sh's package list: a rebuilt image gets a fresh filesystem, and
#       the binaries live outside the volumes. The enablement is re-applied at
#       every boot (docker/aipbx-bootstrap.sh) because the install itself runs
#       only once.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        systemd systemd-sysv sudo git rsync ca-certificates tcpdump cron \
        nftables iproute2 \
    && rm -rf /var/lib/apt/lists/*

COPY docker/aipbx-bootstrap.sh /usr/local/sbin/aipbx-bootstrap
COPY docker/aipbx-bootstrap.service /etc/systemd/system/aipbx-bootstrap.service
RUN chmod 0755 /usr/local/sbin/aipbx-bootstrap \
    && systemctl enable aipbx-bootstrap.service \
    # No console in here: an enabled getty restarts every 5 seconds forever,
    # filling the journal and the log output with noise.
    && systemctl mask getty@tty1.service serial-getty@ttyS0.service

# Documentation only: the compose file publishes these (and the RTP window).
EXPOSE 80 443 5060/udp 5060/tcp 5061/tcp 8089/tcp

# systemd in a container shuts down on SIGRTMIN+3, not SIGTERM.
STOPSIGNAL SIGRTMIN+3

CMD ["/sbin/init"]
