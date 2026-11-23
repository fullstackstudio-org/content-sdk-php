#!/bin/sh
# One container, two processes (php-fpm and nginx), supervised by this script. A shell is ~1 MB resident; supervisord would be ~25 MB.
# If either process exits, the other is stopped and the container exits, so the platform restarts it. SIGTERM stops both cleanly.
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set (php artisan key:generate --show)" >&2
    exit 1
fi

# Upload limit for form files, in MB for one whole request (Content allows 25 MB per file and 50 MB per submission).
# The SDK holds the files in memory and builds the request body from them, so PHP needs about three times the upload plus
# its normal 64 MB. Default 20 MB; above that also raise the pod's memory limit.
UPLOAD_MB="${FSC_UPLOAD_MAX_MB:-20}"
case "$UPLOAD_MB" in
    ''|*[!0-9]*) echo "FSC_UPLOAD_MAX_MB must be a whole number of MB" >&2; exit 1 ;;
esac
if [ "$UPLOAD_MB" -lt 1 ] || [ "$UPLOAD_MB" -gt 55 ]; then
    echo "FSC_UPLOAD_MAX_MB must be between 1 and 55" >&2
    exit 1
fi
MEMORY_MB=$((64 + UPLOAD_MB * 3))

# The environment only exists at runtime, so the caches are built now (not at image build time).
mkdir -p /tmp/nginx-body /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi
printf 'client_max_body_size %sm;\n' "$UPLOAD_MB" > /tmp/nginx-upload.conf
php artisan config:cache
php artisan route:cache
php artisan view:cache

php-fpm -d upload_max_filesize="${UPLOAD_MB}M" -d post_max_size="${UPLOAD_MB}M" -d memory_limit="${MEMORY_MB}M" &
FPM=$!
nginx -e /dev/stderr &
NGINX=$!

stop() {
    kill -TERM "$NGINX" "$FPM" 2>/dev/null || true
    wait "$NGINX" "$FPM" 2>/dev/null || true
    exit 0
}
trap stop TERM INT QUIT

# Wait until the first of the two exits.
while kill -0 "$FPM" 2>/dev/null && kill -0 "$NGINX" 2>/dev/null; do
    sleep 1 &
    wait $! || true
done

echo "a child process exited, stopping" >&2
kill -TERM "$NGINX" "$FPM" 2>/dev/null || true
wait 2>/dev/null || true
exit 1
