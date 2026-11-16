#!/bin/sh
# Starts the API inside the container: first the database migrations, then the PHP web server.
#
# Even when PostgreSQL is healthy, the app container can start a moment before the
# "database" host name resolves, so the migrations are retried a few times before giving up.
set -e

MAX_ATTEMPTS=10
WAIT_SECONDS=3

attempt=1
until php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; do
    if [ "$attempt" -ge "$MAX_ATTEMPTS" ]; then
        echo "Migrations failed after $MAX_ATTEMPTS attempts, stopping." >&2
        exit 1
    fi

    echo "Migrations failed (attempt $attempt of $MAX_ATTEMPTS), retrying in $WAIT_SECONDS seconds..." >&2
    attempt=$((attempt + 1))
    sleep "$WAIT_SECONDS"
done

# "exec" replaces this script with the web server, so it receives the stop signal from Docker directly.
exec php -S 0.0.0.0:8000 -t public
