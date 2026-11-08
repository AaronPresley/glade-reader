# Read-only API

Sign in and open **Settings → API tokens** (`/settings/api-tokens`). Give the token a name, generate it, and copy the secret immediately. The secret is available only in that response and is not retained in browser history. Only a hash is stored in the database. Tokens have the `read` ability, remain valid until revoked, and can be revoked on the same page.

Use HTTPS and send `Authorization: Bearer YOUR_TOKEN` and `Accept: application/json` on each request. Browser sessions alone do not authenticate API requests. API tokens cannot authenticate the web UI or manage tokens.

## Endpoints

| Method | Path                          | Returns                                                   |
| ------ | ----------------------------- | --------------------------------------------------------- |
| GET    | `/api/source-references`      | Your sources, newest ID first                             |
| GET    | `/api/source-references/{id}` | One of your sources                                       |
| GET    | `/api/entries`                | Your entries, including archived entries, newest ID first |
| GET    | `/api/entries/{id}`           | One of your entries                                       |

Responses wrap records in `data`. Lists contain up to 25 records and include Laravel `links` and `meta` pagination fields. Follow `links.next` or pass `?page=2`, etc. No users, user IDs, credentials, or token data are serialized. Other users’ records and missing IDs return 404. There are no create, update, delete, archive, or generation endpoints.

Sources expose `id`, `url`, `status`, `raw_content`, `screenshot_path`, `image_url`, `metadata`, `created_at`, and `updated_at`. `raw_content` is the original captured HTML, not sanitized HTML: treat it as untrusted and sanitize or sandbox it before embedding. `image_url` is a signed screenshot URL valid for 30 minutes, or null when no screenshot exists. Processing step statuses and errors are in `metadata`.

Entries expose `id`, `source_reference_id`, `type`, `status`, `content`, `content_html`, `metadata`, `archived_at`, `created_at`, and `updated_at`. `content` is Markdown; `content_html` renders it using CommonMark with embedded raw HTML stripped and unsafe links disabled. Both are null when content is not yet available. HTML is an article fragment, not the styled app page. Use `source_reference_id` to retrieve its source.

Statuses preserve the model’s uppercase enum values. Source statuses: `NEW`, `PENDING`, `COMPLETED`, `FAILED`. Entry statuses: `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`. Archived entries have a non-null `archived_at`.

Missing, invalid, expired, or revoked tokens return 401. Tokens without `read` return 403. API requests are limited to 60 per minute per authenticated user (429 when exceeded).

## Deployment

Run the normal database migrations (`./rar migrate --force`) to create Sanctum’s `personal_access_tokens` table before using token settings. No new environment variables or external services are required.
