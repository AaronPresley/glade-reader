# AGENTS.md

Keep all instructions in this file brief.

## Architecture

- Use domain-driven organization for app code. Put domain-specific code under `app/Domain/<Context>`.
- If the correct domain context is unclear, confirm the context before adding code, or create a new focused context when the feature clearly introduces one.
- Push back when a request conflicts with project conventions, prior constraints, or the user's likely intent; explain the better path before changing code.

## Environment

- In Amp orbs, also read [.agents/orb-context.md](.agents/orb-context.md).
- Use `./npm`, `./run`, and `./rar` for npm, container shell, and Artisan commands.
- A local queue worker may already be running; do not start another for verification unless explicitly needed.
- When adding or changing environment variables, update `docs/dockge-setup.md` with the production Dockge configuration.
- Secrets belong in Dockge's `.env` panel and should be documented in the `Dockge .env` section, not hardcoded into compose examples or committed files.

## Live debugging

- Use Amp's project-scoped `TEST_URL` as the live API base URL and secret `TEST_TOKEN` as the Bearer token. Read them from the process environment; never print, commit, or include the token in logs or captures. These are Amp debugging variables, not Dockge app configuration.
- After these variables change, run `amp orb restart-processes` to refresh the orb environment. Send the token only to the configured HTTPS host; do not forward it across redirects.
- Default to read-only live requests. Production writes, including URL submissions that queue processing or incur provider costs, require explicit user approval. Use the isolated orb database for disposable fixtures.

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
