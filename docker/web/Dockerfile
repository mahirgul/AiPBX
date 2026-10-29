# The portal — PHP + Apache, and the one-shot bootstrap that seeds a deployment
# (docker/README.md). Rebuilding this image and restarting the `web` service is
# a portal update; Asterisk is a different container and keeps running.
#
# Ubuntu 26.04 on purpose, not a php:* Debian image: the portal shells out to
# `asterisk -rx`, so its CLI has to speak to this deployment's daemon. That
# means the same Asterisk build on both sides — 22.5 from Ubuntu 26.04 — and
# the same PHP the project targets (8.4). A Debian bookworm base would pair an
# Asterisk 20 CLI with an Asterisk 22 daemon.
FROM ubuntu:26.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

# apache2 + libapache2-mod-php: the portal's server, as on a host install.
# The unversioned php-* meta-packages are deliberate: the project needs PHP
# >= 8.4 and Ubuntu 26.04 carries 8.5, so pinning php8.4-* would fail on the
# very base this image is built on. The extensions are the ones the code uses
# (PDO/MariaDB, mbstring/intl for the language files, gd for QR codes and fax
# covers, zip for plugin and firmware archives, curl for the AI runtime and
# plugin HTTP calls, xml for phinx).
# asterisk: the CLI, matched to the daemon image (docker/asterisk/Dockerfile).
# mariadb-client: `aipbx-init` loads seed.sql with it.
# sudo: the portal's own privileged helper is called that way (src/priv_helper.php).
# unzip/git: composer install at build time.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        apache2 libapache2-mod-php \
        php-cli php-mysql php-mbstring php-intl php-zip \
        php-gd php-bcmath php-curl php-xml php-gmp \
        asterisk \
        mariadb-client sudo cron openssl ca-certificates curl git unzip \
        tzdata procps \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers remoteip proxy proxy_http proxy_wstunnel ssl \
    # Same numeric group as the Asterisk image: the configuration volume is
    # written by www-data here and read by the asterisk user there. Group
    # names do not cross containers, group ids do.
    && groupadd --gid 2000 aipbx \
    && usermod --append --groups aipbx www-data \
    # No systemd, no init scripts: Apache is started in the foreground by the
    # entrypoint and Asterisk only ever contributes its CLI here.
    && rm -f /etc/init.d/asterisk

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The application, at the path install.sh uses on a bare host, so the code's
# own relative assumptions (../sounds, ../db/seed.sql, docs/) hold.
COPY . /opt/aipbx
WORKDIR /opt/aipbx/web
RUN composer install --no-interaction --no-progress --prefer-dist --no-dev \
    && rm -rf /root/.composer /root/.cache/composer

COPY docker/web/apache-aipbx.conf /etc/apache2/aipbx-vhost.conf.template
COPY docker/web/entrypoint.sh /usr/local/sbin/aipbx-web-entrypoint
COPY docker/web/aipbx-init.sh /usr/local/sbin/aipbx-init
COPY docker/web/aipbx-config.sh /usr/local/sbin/aipbx-config
RUN chmod 0755 /usr/local/sbin/aipbx-web-entrypoint /usr/local/sbin/aipbx-init \
        /usr/local/sbin/aipbx-config \
    && a2dissite 000-default >/dev/null 2>&1 || true

EXPOSE 80
ENTRYPOINT ["/usr/local/sbin/aipbx-web-entrypoint"]
CMD ["apachectl", "-D", "FOREGROUND"]
