# Dockge Production Setup

Merges and other pushes to `main` run the full Pest unit, feature, and domain test suites before building and pushing both production images through GitHub Actions. Pull requests to `main` run the same tests without publishing.

The workflow publishes `linux/amd64` images as `aaronpresley/glade-reader-web:latest` and `aaronpresley/glade-reader-cli:latest`, plus `sha-<full-commit-sha>` tags for each image. GitHub Actions requires the repository secret `DOCKERHUB_TOKEN`, a Docker Hub personal access token for `aaronpresley` with write access to both repositories. Configure this secret in GitHub, not Dockge.

To set or replace the token, create a Docker Hub personal access token with Read & Write permissions, then run this command and paste the token at the hidden prompt:

```bash
gh secret set DOCKERHUB_TOKEN --repo AaronPresley/glade-reader
```

For manual versioned builds, use `bin/docker-build`. The script publishes two Docker Hub images from the same tag:

- `aaronpresley/glade-reader-web:<tag>` and `aaronpresley/glade-reader-web:latest`
- `aaronpresley/glade-reader-cli:<tag>` and `aaronpresley/glade-reader-cli:latest`

The build script publishes `linux/amd64` images by default for the NAS. If Dockge reports `no matching manifest for linux/amd64`, republish the images with the current script so Docker Hub receives amd64 manifests.

Use the `latest` tags below if you want Lighthouse to auto-deploy each new published build. Use an immutable version tag instead if you want manual promotion.

## Dockge Compose

Create a new Dockge stack and paste this compose file into the Compose section. Deployment-specific values and secrets from the next section go into Dockge's `.env` section.

```yaml
x-app-env: &app_env
    APP_NAME: Glade
    APP_ENV: production
    APP_KEY: '${APP_KEY}'
    APP_DEBUG: 'false'
    APP_URL: 'https://glade.woodchip.club'
    ASSET_URL: 'https://glade.woodchip.club'
    FORCE_HTTPS: 'true'

    SSL_MODE: 'off'
    LOG_CHANNEL: stderr
    LOG_LEVEL: warning
    LOG_OUTPUT_LEVEL: warn

    DB_CONNECTION: pgsql
    DB_HOST: pgsql
    DB_PORT: 5432
    DB_DATABASE: glade
    DB_USERNAME: glade
    DB_PASSWORD: '${DB_PASSWORD}'

    SESSION_DRIVER: database
    SESSION_SECURE_COOKIE: 'true'
    CACHE_STORE: database
    QUEUE_CONNECTION: database
    FILESYSTEM_DISK: s3
    AWS_ACCESS_KEY_ID: '${AWS_ACCESS_KEY_ID}'
    AWS_SECRET_ACCESS_KEY: '${AWS_SECRET_ACCESS_KEY}'
    AWS_DEFAULT_REGION: us-west-2
    AWS_BUCKET: '${AWS_BUCKET}'
    AWS_USE_PATH_STYLE_ENDPOINT: 'false'
    APIFY_API_TOKEN: '${APIFY_API_TOKEN}'
    OPEN_ROUTER_API_KEY: '${OPEN_ROUTER_API_KEY}'
    OPEN_ROUTER_MODEL: '${OPEN_ROUTER_MODEL}'

x-queue-service: &queue_service
    image: aaronpresley/glade-reader-cli:latest
    pull_policy: always
    restart: unless-stopped
    command: php artisan queue:work --tries=3 --timeout=90
    environment:
        <<: *app_env
        CONTAINER_ROLE: queue
        RUN_MIGRATIONS: 'false'
    volumes:
        - app_storage:/var/www/html/storage
    labels:
        com.centurylinklabs.watchtower.enable: 'true'
    depends_on:
        pgsql:
            condition: service_healthy

services:
    app:
        image: aaronpresley/glade-reader-web:latest
        pull_policy: always
        restart: unless-stopped
        ports:
            - '${APP_PORT}:8080'
        environment:
            <<: *app_env
            CONTAINER_ROLE: app
            RUN_MIGRATIONS: 'true'
        volumes:
            - app_storage:/var/www/html/storage
        labels:
            com.centurylinklabs.watchtower.enable: 'true'
        depends_on:
            pgsql:
                condition: service_healthy

    queue-1:
        <<: *queue_service

    queue-2:
        <<: *queue_service

    queue-3:
        <<: *queue_service

    scheduler:
        image: aaronpresley/glade-reader-cli:latest
        pull_policy: always
        restart: unless-stopped
        command: php artisan schedule:work
        environment:
            <<: *app_env
            CONTAINER_ROLE: scheduler
            RUN_MIGRATIONS: 'false'
        volumes:
            - app_storage:/var/www/html/storage
        labels:
            com.centurylinklabs.watchtower.enable: 'true'
        depends_on:
            pgsql:
                condition: service_healthy

    pgsql:
        image: postgres:17-alpine
        restart: unless-stopped
        environment:
            POSTGRES_DB: glade
            POSTGRES_USER: glade
            POSTGRES_PASSWORD: '${DB_PASSWORD}'
        volumes:
            - pgsql_data:/var/lib/postgresql/data
        healthcheck:
            test: ['CMD-SHELL', 'pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}']
            interval: 5s
            timeout: 5s
            retries: 10

volumes:
    app_storage:
    pgsql_data:
```

## Dockge .env

Paste these deployment-specific values and secrets into Dockge's `.env` section for the stack.

```dotenv
APP_PORT=8080
APP_KEY=base64:REPLACE_WITH_REAL_APP_KEY
DB_PASSWORD=REPLACE_WITH_DB_PASSWORD
AWS_ACCESS_KEY_ID=REPLACE_WITH_AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY=REPLACE_WITH_AWS_SECRET_ACCESS_KEY
AWS_BUCKET=woodchip-club-glade
APIFY_API_TOKEN=REPLACE_WITH_APIFY_API_TOKEN
OPEN_ROUTER_API_KEY=REPLACE_WITH_OPEN_ROUTER_API_KEY
OPEN_ROUTER_MODEL=openai/gpt-4o-mini
```

## Lighthouse Auto-Deploy

The `lighthouse` service uses Watchtower to check for updated images every 300 seconds. It only updates containers with this label:

```yaml
com.centurylinklabs.watchtower.enable: 'true'
```

That keeps the watcher scoped to the Glade web, queue, and scheduler containers instead of updating every container on the NAS.

GitHub Actions and `bin/docker-build` both update the `latest` tags when publishing. Lighthouse will detect the changed `latest` image digest, pull the new `-web` and `-cli` images, and recreate the labeled containers.

## First Deploy

Generate a production app key before deployment:

```bash
php artisan key:generate --show
```

Set that value as `APP_KEY`.

The app container runs `php artisan migrate --force` at startup when `CONTAINER_ROLE=app` and `RUN_MIGRATIONS=true`, so first deploy and later restarts both apply pending migrations automatically.

## Notes

- Amp orbs use an isolated SQLite database and local file storage by default; those development-only overrides do not change the Dockge environment above. See [.agents/orb-context.md](../.agents/orb-context.md) for the separate orb workflow.
- Keep `SESSION_SECURE_COOKIE: "true"` when the app is served through HTTPS. Use `"false"` only for plain HTTP testing.
- `SSL_MODE: "off"` is correct when TLS terminates at a NAS reverse proxy or another frontend proxy.
- The reverse proxy must preserve the public `Host` and overwrite `X-Forwarded-Proto` with the browser's scheme (`https`). Laravel trusts this scheme header so signed bookmarklet URLs validate after TLS termination; keep the app port accessible only to the proxy.
- The app listens on container port `8080`; change `APP_PORT` in Dockge's `.env` section if the NAS host port is already in use.
- Database, cache, sessions, and queue state are stored in Postgres.
- Queue work is split across three explicit `queue-*` services. Add or remove `queue-*` service entries to change worker count.
- Article extraction checkpoints one chunk per job on the database queue. Its 60-second provider timeout and 80-second job timeout fit the default 90-second queue visibility interval; keep visibility longer than the job timeout if overriding it. The whole page has no shared provider timeout or fixed chunk-count cap.
- Laravel file storage uses the `s3` disk with the `woodchip-club-glade` bucket in `us-west-2`.
- The `app_storage` volume is only for Laravel's writable runtime storage directory; uploaded/generated app files should be written through Laravel storage to S3.
