# ReviewRadar

ReviewRadar is a PHP and PostgreSQL multi-tenant review analysis app. Company accounts are isolated by `company_id`. Review sentiment and topics are generated through Groq.

## Requirements

- PHP 8.2 or newer with `pdo_pgsql`, `curl`, `mbstring`, `dom`, `xml`, and `zip`
- PostgreSQL 13 or newer
- `pdftotext` from poppler-utils for text-based PDF imports (optional)
- HTTPS for public deployment
- A Groq API key

## Local setup

1. Create the database and an application user with only the permissions the runtime needs:

   ```sql
   CREATE USER reviewradar_app WITH PASSWORD 'use-a-long-random-password';
   CREATE DATABASE reviewradar OWNER reviewradar_app;
   ```

   Use a unique password and replace the example in the command.
2. Copy `.env.example` to `.env` and set the database credentials and `GROQ_API_KEY`. For local HTTP development, set `APP_ENV=local` and `DB_SSLMODE=disable`.
3. Apply the schema with `php scripts/migrate.php` (or `psql -d reviewradar -f schema.sql`).
4. Start the PHP development server from the project directory with `php -d upload_max_filesize=20M -d post_max_size=25M -S 127.0.0.1:8080 -t . router.php`.
5. Open `http://127.0.0.1:8080/` and create a company workspace.

`router.php` blocks `.env`, `src/`, `config/`, `tests/`, and other non-public repository files because PHP's built-in server ignores `.htaccess`. The built-in PHP server is for local development only. Do not use it as the public production server. The `-d` flags raise PHP's upload limits so a 20 MB import file fits inside the 25 MB POST limit.

## Testing

Run the dependency-free test suite from the project directory:

```sh
php tests/run.php
```

The suite covers the request DTOs, analysis validation, JWT signing, rate limiting, CSV/TSV/JSON/PDF/XLSX/DOCX/TXT import parsing, and an end-to-end HTTP flow (signup, ingestion, file parsing, bulk import, listing, export, key rotation, login, deletion) against an in-process Groq mock. It creates temporary tenants in the configured database and removes them when it finishes; it does not call the live Groq API.

## Deploy to Render

The included `render.yaml` provisions a free Render web service and a free Render Postgres database in the same region:

1. Push this repository to GitHub.
2. In the Render Dashboard, choose **New > Blueprint** and select the repository.
3. Enter the `GROQ_API_KEY` value when prompted, then approve the first sync.
4. Render builds the Docker image, applies `schema.sql` on startup through `scripts/migrate.php`, and serves the app at its `onrender.com` URL.

The container runs `scripts/migrate.php` before Apache starts, so `schema.sql` must remain idempotent. Set `AUTO_MIGRATE=false` to skip this step.

Render's free Postgres plan expires 30 days after creation. Upgrade the database to a paid plan before then to keep its data.

## Production deployment

- Serve this directory through Apache with `.htaccess` enabled or configure equivalent Nginx rules. Keep `.env`, `schema.sql`, `src/`, `config/`, and repository metadata outside the public document root where possible; otherwise deny direct web access to them.
- Run PHP-FPM behind a maintained HTTPS reverse proxy. Set `APP_ENV=production`, a unique `JWT_SECRET`, `GROQ_API_KEY`, `GROQ_CONCURRENCY`, and either `DATABASE_URL` or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_SSLMODE` in the process environment or a deployment secret store. Never commit `.env`.
- Raise PHP's `upload_max_filesize` to at least `20M` and `post_max_size` to at least `25M` if you want to accept the largest supported import files. Ensure the PHP process can execute `pdftotext` for PDF imports.
- Generate `JWT_SECRET` with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and keep it stable across app instances.
- Use a dedicated PostgreSQL role for the application. Do not use the PostgreSQL superuser for the application.
- Keep the dashboard and API on the same origin. `APP_CORS_ORIGIN` is an optional exact origin for cross-origin API integrations; wildcard origins are not supported, and dashboard cookies are same-origin only.
- Terminate HTTPS before public traffic reaches the application. Production sessions are marked `Secure`, `HttpOnly`, and `SameSite=Strict`.
- Monitor PHP and web-server logs. API responses suppress internal exception details; Groq or database failures are logged server-side.
- Configure backups and verify restoration of the PostgreSQL database before accepting customer data.
- The built-in rate limiter stores short-lived hashed request counters in the host's temporary directory. For multiple application hosts, move this limiter to a shared store before scaling out.

## Available product paths

- Company signup and login
- Single-review ingestion for integrations at `POST /api/submit_review.php`
- File bulk import through the Sources page using `POST /api/parse_import.php` and `POST /api/import_reviews.php`
- CSV, TSV, JSON, PDF, XLSX, DOCX, TXT, and HTML review imports
- CLI bulk import for large files and scheduled jobs (`scripts/import_reviews.php`)
- Workspace analytics and review listing
- API key reveal and rotation in workspace settings
- Workspace deletion with password confirmation
- Dashboard, reviews, themes, alerts, and CSV export based on stored workspace reviews

## Bulk import

The Sources page has one import control. It uploads the file to `POST /api/parse_import.php`, receives normalized rows, then sends them to `POST /api/import_reviews.php` in batches of 25 while showing a progress bar. Analysis runs concurrently (`GROQ_CONCURRENCY`, default 5, clamped to 1–10), so a batch does not wait for 25 sequential Groq calls.

Supported formats:

| Format | Notes |
| --- | --- |
| CSV / TSV | Header row with review columns; common aliases are accepted |
| JSON | List of objects or `{"reviews": [...]}` |
| PDF | Text-based PDFs via `pdftotext`; scanned/image-only PDFs need OCR |
| XLSX | First worksheet; first row is treated as headers |
| DOCX | Tables (first row headers) or paragraph text |
| TXT / MD | Blank-line-separated paragraphs, or sentences on separate lines |
| HTML | Visible table/text content is extracted |

Review rows use these fields (aliases are accepted for common export names):

| Column | Required | Notes |
| --- | --- | --- |
| `text` | yes | Review body, up to 5,000 characters |
| `author_name` | no | Reviewer display name |
| `rating` | no | Number from 1 to 5 |
| `source` | no | Label such as `g2`, `trustpilot`, or `web`; defaults to `api` |
| `source_url` | no | Link to the original review |
| `external_id` | no | Provider review ID; makes retries idempotent |

Limits: 1,000 reviews and 20 MB per file. Parse first, then import at most 25 rows per API request; the UI and CLI batch automatically. The import endpoint also accepts a multipart file directly, but that path is also capped at 25 rows.

```sh
curl -X POST http://127.0.0.1:8080/api/parse_import.php \
  -H 'X-API-Key: YOUR_COMPANY_API_KEY' \
  -F 'file=@reviews.pdf'

curl -X POST http://127.0.0.1:8080/api/import_reviews.php \
  -H 'X-API-Key: YOUR_COMPANY_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"reviews":[{"text":"Fast delivery and great support","rating":5}]}'

php scripts/import_reviews.php \
  --api=http://127.0.0.1:8080 \
  --key=YOUR_COMPANY_API_KEY \
  --file=reviews.xlsx
```

Every imported review is analyzed with Groq. Duplicate `external_id` values are rejected for that company. Review-site connectors, team invitations, billing, and email/Slack alert delivery are not enabled. The interface identifies these as unavailable rather than showing fabricated connection state.

## Launch gates

The code is suitable for a single-host staging deployment after production environment variables are supplied. Do not open unrestricted public registration until an email-verification and password-recovery flow is connected to a transactional email provider. Publish operator identity, Terms, Privacy, support contact, and data-retention details before accepting customer review data. These require the service operator's real business and contact information and are not fabricated in this repository.

Review ingestion is rate limited to protect the Groq account, and there are no subscription tiers or monthly quota restrictions in the application.
