# Worktide — Deployment (Docker Compose + Coolify)

Rollout of the three Worktide apps to a Coolify host, with **auto-deploy on
every tagged GitHub Release**.

| Domain | App | Repo | Coolify build pack |
|---|---|---|---|
| `api.worktide.wappler.systems` | Symfony API (FrankenPHP) + worker + scheduler | `Worktide-IO/worktide` | Docker Compose (`compose.prod.yaml`) |
| `worktide.wappler.systems` | Web SPA (nginx) | `Worktide-IO/worktide-web` | Dockerfile |
| `kunden.wappler.systems` | Customer Portal SPA (nginx) | `Worktide-IO/worktide-portal` | Dockerfile |
| `worktide-mercure.wappler.systems` | Mercure hub | image `dunglas/mercure` | (Coolify service) |
| — (internal) | MySQL 8 | — | Coolify managed database |

Runtime facts that shaped this setup:
- DB is **MySQL 8** (migrations are MySQL dialect, `.env.local` DSN `serverVersion=8.0`).
- Messenger transport is **`doctrine://`** → no Redis/RabbitMQ needed; the DB carries the queues.
- `SEARCH_PROVIDER=mysql` → **Meilisearch is not required**.
- File storage is **S3** (`FILE_STORAGE_ADAPTER=s3`, adapter already implemented).
- Vite bakes `VITE_*` at **build time** → they are Coolify **Build Variables**, not runtime env.

---

## 0. Prerequisites

1. A server running Coolify (v4), with its proxy (Traefik) enabled.
2. DNS **A/AAAA records** pointing all four hostnames to the Coolify host:
   `api.worktide`, `worktide`, `kunden`, `worktide-mercure` (`.wappler.systems`).
3. A GitHub source connected in Coolify (**Sources → GitHub App**) with access to
   the `Worktide-IO` repos. (A per-repo Deploy Key also works.)
4. An S3 bucket + credentials (UpCloud / AWS / Cloudflare R2 / self-hosted MinIO).

---

## 1. MySQL 8 (managed database)

Coolify → **+ New → Database → MySQL 8**.
- Note the generated credentials. Coolify exposes an **internal connection URL**
  usable by other resources on the same project network.
- Enable **scheduled backups**.
- Build the Symfony DSN for later:
  ```
  DATABASE_URL="mysql://<user>:<pass>@<internal-host>:3306/<db>?serverVersion=8.0&charset=utf8mb4"
  ```
  `<internal-host>` is the DB service name/host Coolify shows under "Internal URL".

## 2. Mercure hub (own service)

Coolify → **+ New → Service → (Docker image)** `dunglas/mercure`, or a small
Compose resource. Set:
```
SERVER_NAME=:80
MERCURE_PUBLISHER_JWT_KEY=<long-random-secret>
MERCURE_SUBSCRIBER_JWT_KEY=<same-or-another-long-random-secret>
MERCURE_EXTRA_DIRECTIVES=cors_origins https://worktide.wappler.systems https://kunden.wappler.systems
```
- Domain: `https://worktide-mercure.wappler.systems`.
- The `MERCURE_*_JWT_KEY` here must match what the **backend** signs with
  (`MERCURE_JWT_SECRET`, see §3).

## 3. Backend API — `worktide`

Coolify → **+ New → Application → Public/Private Repository** → `Worktide-IO/worktide`.
- **Build Pack: Docker Compose**, Compose file: `compose.prod.yaml`.
- **Branch:** `main` (release tags live on this branch).
- **Domain:** assign `https://api.worktide.wappler.systems` to the **`app`** service
  (Coolify "Domains" field; internal port **80**).

### Runtime environment variables (Coolify → Environment Variables)

Secrets — set as **runtime** (not build) variables. These override the baked
`.env.local.php` defaults at container start.

| Variable | Value / note |
|---|---|
| `APP_ENV` | `prod` |
| `APP_SECRET` | long random string |
| `DATABASE_URL` | from §1 |
| `JWT_PASSPHRASE` | passphrase for the generated JWT keypair (stable!) |
| `CORS_ALLOW_ORIGIN` | `^https://(worktide|kunden)\.wappler\.systems$` |
| `DEFAULT_URI` / `API_HOST` | `https://api.worktide.wappler.systems` |
| `SPA_BASE_URL` | `https://worktide.wappler.systems` |
| `PORTAL_BASE_URL` | `https://kunden.wappler.systems` |
| `MERCURE_URL` | `https://worktide-mercure.wappler.systems/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | same as `MERCURE_URL` |
| `MERCURE_JWT_SECRET` | must equal Mercure's `MERCURE_PUBLISHER_JWT_KEY` (§2) |
| `FILE_STORAGE_ADAPTER` | `s3` |
| `S3_KEY` / `S3_SECRET` | bucket credentials |
| `S3_BUCKET` / `S3_REGION` | bucket name / region |
| `S3_ENDPOINT` | provider endpoint (empty for AWS; set for MinIO/UpCloud/R2) |
| `S3_USE_PATH_STYLE` | `true` for MinIO/UpCloud, `false` for AWS |
| `S3_PREFIX` | optional key prefix, e.g. `worktide/` |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` | LLM access |
| `TAVILY_API_KEY`, `BUILTWITH_API_KEY` | research/enrichment |
| `MAILER_DSN`, `MAILER_FROM` | outbound mail |
| `OAUTH_GMAIL_*`, `OAUTH_GRAPH_*`, `OAUTH_LINKEDIN_*`, `OAUTH_REDIRECT_BASE` | channel OAuth |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` (default) |
| `SEARCH_PROVIDER` | `mysql` |
| `TZ` | `Europe/Berlin` |

Notes:
- The **`app`** service runs migrations on boot (`AUTO_MIGRATE=1`); `worker` and
  `scheduler` skip them — no schema race.
- JWT keypair is generated once into a **named volume** (`jwt_keys`) so tokens
  survive restarts. Keep `JWT_PASSPHRASE` stable.
- Tune the cron cadences in `frankenphp/crontab`; lexoffice syncs are disabled
  there by default (need `--apply` + per-channel API key).

### Deploy & verify

First deploy runs migrations automatically. Then smoke-test:
```
curl -sS https://api.worktide.wappler.systems/v1        # API Platform entrypoint
```

## 4. Web SPA — `worktide-web`

Coolify → **+ New → Application** → `Worktide-IO/worktide-web`.
- **Build Pack: Dockerfile**. Branch `main`. Domain `https://worktide.wappler.systems` (port 80).
- **Build Variables** (tick "Build Variable" — Vite needs them at build time):

| Variable | Value |
|---|---|
| `VITE_API_BASE` | `https://api.worktide.wappler.systems/v1` |
| `VITE_API_PUBLIC_BASE` | `https://api.worktide.wappler.systems/v1` |
| `VITE_MERCURE_HUB_URL` | `https://worktide-mercure.wappler.systems/.well-known/mercure` |
| `VITE_REFINE_DEVTOOLS` | `false` |

## 5. Portal SPA — `worktide-portal`

Coolify → **+ New → Application** → `Worktide-IO/worktide-portal`.
- **Build Pack: Dockerfile**. Branch `main`. Domain `https://kunden.wappler.systems` (port 80).
- **Build Variable:**

| Variable | Value |
|---|---|
| `VITE_API_BASE` | `https://api.worktide.wappler.systems/v1` |

## 6. Auto-deploy on every release

Two moving parts per repo:

1. **Coolify deploy webhook** — on each app: **Settings → Webhooks** shows a
   *Deploy Webhook* URL (contains `?uuid=<resource-uuid>`). Also create a Coolify
   **API token** (Keys & Tokens).
2. **GitHub secrets** — in each repo (*Settings → Secrets and variables →
   Actions*), add:
   - `COOLIFY_DEPLOY_WEBHOOK` = that deploy URL
   - `COOLIFY_API_TOKEN` = the Coolify API token

The committed `.github/workflows/deploy.yml` fires on `release: published`, calls
the webhook, and fails the job on a non-2xx response. Coolify then pulls the
tagged commit, rebuilds, and redeploys.

**Cutting a release:**
```
git tag v1.2.3 && git push origin v1.2.3
# then GitHub → Releases → Draft a new release → pick the tag → Publish
```
Publishing triggers the workflow → all-in-one deploy. (Turn OFF Coolify's own
"Automatic Deployment on push" so pushes to `main` don't deploy — only releases.)

> Prefer deploy-on-push instead? Then skip the workflow and just enable Coolify's
> "Automatic Deployment" on each app; every push to `main` redeploys.

---

## Operational notes

- **Migrations** run automatically on the `app` container at each deploy. Watch
  the deploy logs; a failed migration fails the deploy.
- **Logs / workers:** the `worker` service consumes `async ai_agents search`; the
  `scheduler` runs supercronic. Check both in Coolify's logs if async jobs or
  cron tasks stall.
- **Zero-downtime:** Compose deployments briefly stop/start. For rolling updates,
  enable Coolify's health-check-gated deployment (the `app` healthcheck hits the
  Caddy admin `/metrics` on :2019).
- **FrankenPHP worker mode (optional perf boost):** `composer require
  runtime/frankenphp-symfony`, then set Coolify env `FRANKENPHP_CONFIG=import
  worker.Caddyfile`. Redeploy. (Long-running kernel — watch for state leaks.)
- **Scaling workers:** raise the `worker` replica count in Coolify, or split the
  transports into separate services (`messenger:consume async` vs `ai_agents`).
