# Amp orb context

- `.agents/setup` installs PHP 8.4, Composer, locked PHP/JS dependencies, and builds assets. Amp snapshots these; do not reinstall dependencies on resume.
- `./run`, `./rar`, and `./npm` use native tools when `AMP_ORB=1`; outside orbs they retain the Docker Compose workflow.
- A newly created `.env` uses SQLite (`database/orb.sqlite`) and local file storage. Setup preserves an existing `.env` and app key, and only migrates the isolated orb database. Tests use in-memory SQLite.
- Run `./run composer test`, `./npm run build`, and `./run vendor/bin/pint --test` to verify changes.
- Run `amp orb services ensure` to start the supervised app and get its portal URL. It serves built assets; rebuild after frontend edits. Do not run a second queue worker by default.
- Register a disposable account at `/register` for browser testing. Never run `db:seed`: the current seeder drops and recreates tables.
- External S3, Apify, and OpenRouter operations require credentials configured separately in Amp secrets. Setup does not authenticate or copy credentials. Production remains PostgreSQL/S3 as documented in `docs/dockge-setup.md`.
