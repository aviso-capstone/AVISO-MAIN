#!/bin/bash
# Boot order: cache config → start web (health check passes) → migrate →
# seed on first deploy → start Reverb + queue worker.
# No `set -e`: the web server must stay up even if a later step fails.

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "==> ERROR: APP_KEY is not set. Generate one with: php artisan key:generate --show"
fi
if [ -z "$DB_HOST" ] && [ -z "$DB_URL" ]; then
    echo "==> WARNING: DB_HOST / DB_URL not set; the database connection will fail."
fi

echo "==> Caching config, routes and events..."
php artisan config:cache || echo "WARNING: config:cache failed"
php artisan route:cache  || echo "WARNING: route:cache failed"
php artisan event:cache  || echo "WARNING: event:cache failed"
chown -R www-data:www-data storage bootstrap/cache

echo "==> Starting nginx + php-fpm..."
rm -f /tmp/aviso-ready /var/run/supervisor.sock
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &
SUPERVISOR_PID=$!

for i in $(seq 1 30); do
    curl -fs -o /dev/null http://127.0.0.1:8080/health && break
    sleep 1
done

echo "==> Running migrations..."
php artisan migrate --force || echo "WARNING: migrations failed"

USER_COUNT=$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -1)
if [ "$USER_COUNT" = "0" ]; then
    echo "==> Empty database: seeding admin account and address data..."
    php -d memory_limit=512M artisan db:seed --force || echo "WARNING: seeding failed"
else
    echo "==> Database has ${USER_COUNT:-unknown} users; skipping seed."
fi

echo "==> Starting Reverb and the queue worker..."
# They poll for this file (see docker/supervisord.conf).
touch /tmp/aviso-ready

echo "==> AVISO is up."
wait $SUPERVISOR_PID
