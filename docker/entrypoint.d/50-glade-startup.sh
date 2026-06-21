#!/bin/sh

set -e

run_migrations() {
    if [ ! -f "${APP_BASE_DIR}/artisan" ]; then
        echo "Cannot run migrations: artisan was not found at ${APP_BASE_DIR}/artisan." >&2
        exit 1
    fi

    if [ "${APP_ENV:-}" = "production" ]; then
        echo "Running production migrations."
        php "${APP_BASE_DIR}/artisan" migrate --force
    else
        echo "Running migrations for APP_ENV=${APP_ENV:-local}."
        php "${APP_BASE_DIR}/artisan" migrate
    fi
}

case "${CONTAINER_ROLE:-}" in
    app)
        if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
            run_migrations
        fi
        ;;
    queue|scheduler|"")
        ;;
    *)
        echo "No startup tasks configured for CONTAINER_ROLE=${CONTAINER_ROLE}."
        ;;
esac

exit 0
