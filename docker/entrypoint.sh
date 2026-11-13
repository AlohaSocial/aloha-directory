#!/bin/sh
# Runs database migrations (app container only) before the main process.
set -eu
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php /app/bin/console cache:clear --no-interaction
    php /app/bin/console app:tick --site --no-interaction || true
fi
exec "$@"
