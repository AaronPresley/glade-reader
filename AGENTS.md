# AGENTS.md

Keep all instructions in this file brief.

## Architecture

- Use domain-driven organization for app code. Put domain-specific code under `app/Domain/<Context>`.
- If the correct domain context is unclear, confirm the context before adding code, or create a new focused context when the feature clearly introduces one.
- Push back when a request conflicts with project conventions, prior constraints, or the user's likely intent; explain the better path before changing code.

## Environment

- Use `./npm`, `./run`, and `./rar` for npm, container shell, and Artisan commands.
- When adding or changing environment variables, update `docs/dockge-setup.md` with the production Dockge configuration.
- Secrets belong in Dockge's `.env` panel and should be documented in the `Dockge .env` section, not hardcoded into compose examples or committed files.

## Testing

- For now, add tests only for controllers unless explicitly asked otherwise.
- Match controller tests to the domain context naming/path.
- Keep controller tests high-level integration/smoke tests; avoid granular cases unless explicitly requested.

## UI

- Use shadcn/ui with Base UI for future UI elements.
- First look for an installed component that can achieve the goal.
- If a missing shadcn/Base UI component clearly fits the upcoming task, recommend only highly relevant options before implementation.
- NEVER install a new shadcn component without explicit user confirmation.
- Alert the user if Radix UI shadcn docs/components appear to be requested; this project uses Base UI.
