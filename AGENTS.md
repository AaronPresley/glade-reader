# AGENTS.md

Keep all instructions in this file brief.

## Architecture

- Use domain-driven organization for app code. Put domain-specific code under `app/Domain/<Context>`.
- If the correct domain context is unclear, confirm the context before adding code, or create a new focused context when the feature clearly introduces one.

## Environment

- When adding or changing environment variables, update `docs/dockge-setup.md` with the production Dockge configuration.
- Secrets belong in Dockge's `.env` panel and should be documented in the `Dockge .env` section, not hardcoded into compose examples or committed files.
