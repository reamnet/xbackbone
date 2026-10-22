#!/bin/sh
set -eu
cd /app/www/public
# Match LinuxServer's numeric identity so /mnt/data requires no ownership rewrite.
if [ "$(id -g www-data)" != "$PGID" ]; then groupmod -o -g "$PGID" www-data; fi
if [ "$(id -u www-data)" != "$PUID" ]; then usermod -o -u "$PUID" www-data; fi
data=/config/www/xbackbone
if [ ! -f "$data/config.php" ]; then
    echo "Existing XBackBone 3.8.2 config.php is required at $data/config.php. Mount the existing /config volume; do not initialize a replacement database." >&2
    exit 1
fi
mkdir -p "$data/storage" "$data/logs" "$data/resources/database" "$data/resources/sessions" "$data/resources/chunks" "$data/resources/previews" "$data/static/bootstrap"
cp -an static/bootstrap/. "$data/static/bootstrap/"
for path in storage logs resources/database resources/sessions resources/chunks resources/previews static/bootstrap; do
    rm -rf "$path"
    ln -s "$data/$path" "$path"
done
ln -sfn "$data/config.php" config.php
# These are runtime directories, not originals in /mnt/data or the persisted config.
chown -R www-data:www-data resources/cache "$data/logs" "$data/resources/sessions" "$data/resources/chunks" "$data/resources/previews"
# Reuse LinuxServer's certificate/key when HTTPS was served by the container.
: > /etc/nginx/xbackbone-tls.conf
cert="${SSL_CERT_PATH:-/config/keys/cert.crt}"
key="${SSL_KEY_PATH:-/config/keys/cert.key}"
if [ -f "$cert" ] && [ -f "$key" ]; then
    printf 'listen 443 ssl;
ssl_certificate %s;
ssl_certificate_key %s;
ssl_protocols TLSv1.2 TLSv1.3;
' "$cert" "$key" > /etc/nginx/xbackbone-tls.conf
fi
php docker/upgrade.php
exec "$@"
