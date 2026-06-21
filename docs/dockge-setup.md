# Dockge Production Setup

This app is built for production with `bin/docker-build`. The script publishes two Docker Hub images from the same tag:

- `aaronpresley/glade-reader-web:<tag>` and `aaronpresley/glade-reader-web:latest`
- `aaronpresley/glade-reader-cli:<tag>` and `aaronpresley/glade-reader-cli:latest`

The build script publishes `linux/amd64` images by default for the NAS. If Dockge reports `no matching manifest for linux/amd64`, republish the images with the current script so Docker Hub receives amd64 manifests.

Use the `latest` tags below if you want Lighthouse to auto-deploy each new published build. Use an immutable version tag instead if you want manual promotion.

## Dockge Compose

Create a new Dockge stack and paste this compose file into the Compose section. Only the secret values from the next section need to go into Dockge's `.env` section.

```yaml
services:
  app:
    image: aaronpresley/glade-reader-web:latest
    pull_policy: always
    restart: unless-stopped
    ports:
      - "8080:8080"
    environment: &app_env
      APP_NAME: Glade
      APP_ENV: production
      APP_KEY: "${APP_KEY}"
      APP_DEBUG: "false"
      APP_URL: "https://glade.woodchip.club"
      ASSET_URL: "https://glade.woodchip.club"
      FORCE_HTTPS: "true"

      SSL_MODE: "off"
      LOG_CHANNEL: stderr
      LOG_LEVEL: warning
      LOG_OUTPUT_LEVEL: warn

      DB_CONNECTION: pgsql
      DB_HOST: pgsql
      DB_PORT: 5432
      DB_DATABASE: glade
      DB_USERNAME: glade
      DB_PASSWORD: "${DB_PASSWORD}"

      SESSION_DRIVER: database
      SESSION_SECURE_COOKIE: "true"
      CACHE_STORE: database
      QUEUE_CONNECTION: database
      FILESYSTEM_DISK: local
    volumes:
      - app_storage:/var/www/html/storage
    labels:
      com.centurylinklabs.watchtower.enable: "true"
    depends_on:
      pgsql:
        condition: service_healthy

  queue:
    image: aaronpresley/glade-reader-cli:latest
    pull_policy: always
    restart: unless-stopped
    command: php artisan queue:work --tries=3 --timeout=90
    environment: *app_env
    volumes:
      - app_storage:/var/www/html/storage
    labels:
      com.centurylinklabs.watchtower.enable: "true"
    depends_on:
      pgsql:
        condition: service_healthy

  scheduler:
    image: aaronpresley/glade-reader-cli:latest
    pull_policy: always
    restart: unless-stopped
    command: php artisan schedule:work
    environment: *app_env
    volumes:
      - app_storage:/var/www/html/storage
    labels:
      com.centurylinklabs.watchtower.enable: "true"
    depends_on:
      pgsql:
        condition: service_healthy

  migrate:
    image: aaronpresley/glade-reader-cli:latest
    pull_policy: always
    profiles:
      - tools
    command: php artisan migrate --force
    environment: *app_env
    volumes:
      - app_storage:/var/www/html/storage
    depends_on:
      pgsql:
        condition: service_healthy

  pgsql:
    image: postgres:17-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: glade
      POSTGRES_USER: glade
      POSTGRES_PASSWORD: "${DB_PASSWORD}"
    volumes:
      - pgsql_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 5s
      retries: 10

  lighthouse:
    image: containrrr/watchtower:latest
    restart: unless-stopped
    command:
      - --label-enable
      - --cleanup
      - --interval
      - "300"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock

volumes:
  app_storage:
  pgsql_data:
```

## Dockge .env

Paste only the secrets into Dockge's `.env` section for the stack.

```dotenv
APP_KEY=base64:REPLACE_WITH_REAL_APP_KEY
DB_PASSWORD=REPLACE_WITH_DB_PASSWORD
```

## Lighthouse Auto-Deploy

The `lighthouse` service uses Watchtower to check for updated images every 300 seconds. It only updates containers with this label:

```yaml
com.centurylinklabs.watchtower.enable: "true"
```

That keeps the watcher scoped to the Glade web, queue, and scheduler containers instead of updating every container on the NAS.

When `bin/docker-build` publishes a new tag, it also moves both `latest` tags to that build. Lighthouse will detect the changed `latest` image digest, pull the new `-web` and `-cli` images, and recreate the labeled containers.

## First Deploy

Generate a production app key before deployment:

```bash
php artisan key:generate --show
```

Set that value as `APP_KEY`.

After the database is healthy, run the `migrate` service once from Dockge to create the database tables.

## Notes

- Keep `SESSION_SECURE_COOKIE: "true"` when the app is served through HTTPS. Use `"false"` only for plain HTTP testing.
- `SSL_MODE: "off"` is correct when TLS terminates at a NAS reverse proxy or another frontend proxy.
- The app listens on container port `8080`; change the left side of `8080:8080` if the NAS host port is already in use.
- Database, cache, sessions, and queue state are stored in Postgres.
- Runtime app files are stored in the `app_storage` volume.
