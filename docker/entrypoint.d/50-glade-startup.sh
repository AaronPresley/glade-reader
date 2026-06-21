#!/bin/sh

set -e

ensure_artisan() {
    if [ ! -f "${APP_BASE_DIR}/artisan" ]; then
        echo "Cannot run startup task: artisan was not found at ${APP_BASE_DIR}/artisan." >&2
        exit 1
    fi
}

run_migrations() {
    ensure_artisan

    if [ "${APP_ENV:-}" = "production" ]; then
        echo "Running production migrations."
        php "${APP_BASE_DIR}/artisan" migrate --force
    else
        echo "Running migrations for APP_ENV=${APP_ENV:-local}."
        php "${APP_BASE_DIR}/artisan" migrate
    fi
}

run_optimize() {
    ensure_artisan

    if [ "${APP_ENV:-}" = "production" ]; then
        echo "Caching Laravel framework bootstrap, configuration, routes, events, and views."
        php "${APP_BASE_DIR}/artisan" optimize
    else
        echo "Skipping Laravel optimize for APP_ENV=${APP_ENV:-local}."
    fi
}

case "${CONTAINER_ROLE:-}" in
    app)
        if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
            run_migrations
        fi
        run_optimize
        ;;
    queue|scheduler)
        run_optimize
        ;;
    "")
        ;;
    *)
        echo "No startup tasks configured for CONTAINER_ROLE=${CONTAINER_ROLE}."
        ;;
esac

exit 0
