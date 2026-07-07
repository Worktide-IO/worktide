# Worktide — Installation via Docker Compose (self-hosted, ohne Coolify)

Diese Anleitung deployt die **Worktide-API** (FrankenPHP) samt Worker, Scheduler,
**MySQL 8** und **Mercure-Hub** als **eigenständigen Docker-Compose-Stack** auf einem
beliebigen Docker-Host (VPS/Server). Alles läuft in *einem* Compose-Projekt und damit
in *einem* Docker-Netzwerk — dadurch entfallen die Cross-Resource-Netzwerkprobleme,
die eine Managed-Plattform (z.B. Coolify) verursachen kann.

Die beiden Frontends (`worktide-web`, `worktide-portal`) sind statische SPAs und werden
am Ende kurz behandelt.

---

## 1. Voraussetzungen

- Ein Server mit **Docker Engine ≥ 24** und **Docker Compose v2** (`docker compose version`).
- Offene Ports **80** und **443** (TLS/ACME).
- DNS-**A-Records**, die auf die Server-IP zeigen:
  - `api.worktide.example.com` (API)
  - `worktide-mercure.example.com` (Mercure-Hub) — optional, falls Realtime genutzt wird
  - `worktide.example.com`, `kunden.example.com` (Frontends)
- Ein S3-kompatibles Bucket + Zugangsdaten (AWS/MinIO/Hetzner/…), falls `FILE_STORAGE_ADAPTER=s3`.

> Ersetze `example.com` durchgängig durch deine echte Domain.

---

## 2. Projekt holen

```bash
git clone https://github.com/Worktide-IO/worktide.git
cd worktide
git checkout main
```

---

## 3. Produktions-Compose anlegen

Lege neben dem Repo eine **`compose.selfhosted.yaml`** an (eigenständiger Stack —
MySQL + Mercure inklusive, TLS macht FrankenPHP/Caddy automatisch):

```yaml
x-app: &app
  build:
    context: .
    target: frankenphp_prod
  restart: unless-stopped
  env_file: [.env.prod]
  depends_on:
    database:
      condition: service_healthy
  volumes:
    - jwt_keys:/app/config/jwt
    - app_var:/app/var

services:
  app:
    <<: *app
    environment:
      AUTO_MIGRATE: "1"                       # nur der Web-Container migriert
      SERVER_NAME: "api.worktide.example.com" # FrankenPHP holt automatisch ein LE-Cert
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"                         # HTTP/3
    healthcheck:
      test: ["CMD", "curl", "-sS", "-o", "/dev/null", "http://localhost:80/"]
      interval: 20s
      timeout: 5s
      retries: 5
      start_period: 120s

  worker:
    <<: *app
    environment:
      AUTO_MIGRATE: "0"
    command: ["php","bin/console","messenger:consume","async","ai_agents","search","--time-limit=3600","--memory-limit=256M","-vv"]

  scheduler:
    <<: *app
    environment:
      AUTO_MIGRATE: "0"
    # -no-reap is required: supercronic runs as PID 1 here and its zombie
    # reaper fatals after ~12s in this image, crash-looping the container so no
    # cron jobs run. supercronic already waits on its own direct job children
    # (php bin/console …), so disabling the PID-1 reaper is safe. (Alternative:
    # `init: true` on this service → Docker's tini becomes PID 1 and supercronic
    # auto-disables its reaper.)
    command: ["supercronic","-no-reap","/etc/crontab"]

  database:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: worktide
      MYSQL_USER: worktide
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:?set MYSQL_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?set MYSQL_ROOT_PASSWORD}
    command: ["--character-set-server=utf8mb4","--collation-server=utf8mb4_unicode_ci"]
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD","mysqladmin","ping","-h","127.0.0.1","-uworktide","-p${MYSQL_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s

  mercure:
    image: dunglas/mercure
    restart: unless-stopped
    environment:
      SERVER_NAME: "worktide-mercure.example.com"
      MERCURE_PUBLISHER_JWT_KEY: ${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}
      MERCURE_SUBSCRIBER_JWT_KEY: ${MERCURE_JWT_SECRET}
      MERCURE_EXTRA_DIRECTIVES: |
        cors_origins https://worktide.example.com https://kunden.example.com
    # Mercure braucht eigene 80/443 — auf demselben Host mit `app` kollidiert das.
    # Zwei Optionen: (a) Mercure auf einen zweiten Host/IP, oder
    # (b) einen Reverse-Proxy (Traefik/Caddy) davor (siehe Abschnitt 7).
    # Für den Einstieg ohne Realtime kann dieser Service entfallen.
    volumes:
      - mercure_data:/data
      - mercure_config:/config

volumes:
  jwt_keys:
  app_var:
  db_data:
  mercure_data:
  mercure_config:
```

> **Port-80/443-Konflikt:** `app` und `mercure` wollen beide 80/443. Auf *einem* Host
> geht das nur mit einem vorgeschalteten Reverse-Proxy (Abschnitt 7). Für einen ersten,
> einfachen Rollout **ohne Realtime** lässt du den `mercure`-Service weg und setzt
> `MERCURE_URL`/`MERCURE_PUBLIC_URL` auf einen später erreichbaren Hub.

---

## 4. Produktions-Env `.env.prod`

Lege `.env.prod` an (wird von allen App-Containern geladen). Secrets **nicht** committen.

```dotenv
APP_ENV=prod
APP_SECRET=__$(openssl rand -hex 32)__          # einmalig generieren, dann eintragen
TZ=Europe/Berlin

# DB — Hostname ist der Compose-Servicename `database`
DATABASE_URL="mysql://worktide:__MYSQL_PASSWORD__@database:3306/worktide?serverVersion=8.0&charset=utf8mb4"

# Messenger nutzt die DB (kein Redis nötig)
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# Suche: mysql = DB (kein Meilisearch); sonst Meilisearch-Service ergänzen
SEARCH_PROVIDER=mysql

# JWT (lexik) — Passphrase stabil halten; Keypair wird beim Boot erzeugt
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=__$(openssl rand -hex 32)__

# URLs / CORS
DEFAULT_URI=https://api.worktide.example.com
API_HOST=https://api.worktide.example.com
SPA_BASE_URL=https://worktide.example.com
PORTAL_BASE_URL=https://kunden.example.com
CORS_ALLOW_ORIGIN=^https://(worktide|kunden)\.example\.com$

# Mercure (falls genutzt) — Secret muss == MERCURE_PUBLISHER_JWT_KEY des Hubs sein
MERCURE_URL=https://worktide-mercure.example.com/.well-known/mercure
MERCURE_PUBLIC_URL=https://worktide-mercure.example.com/.well-known/mercure
MERCURE_JWT_SECRET=__$(openssl rand -hex 32)__

# S3 (Beispiel Hetzner Object Storage; leer lassen = lokaler Storage)
FILE_STORAGE_ADAPTER=s3
S3_ENDPOINT=https://nbg1.your-objectstorage.com
S3_REGION=nbg1
S3_KEY=__key__
S3_SECRET=__secret__
S3_BUCKET=__bucket__
S3_USE_PATH_STYLE=false
S3_PREFIX=

# LLM / Enrichment / Mail / OAuth (nach Bedarf)
ANTHROPIC_API_KEY=__key__
ANTHROPIC_MODEL=claude-opus-4-8
MAILER_DSN=smtp://user%40example.com:__pw__@mail.example.com:587
MAILER_FROM=worktide@example.com
MAILER_FROM_NAME=Meine Firma
# TAVILY_API_KEY=... BUILTWITH_API_KEY=... OAUTH_GMAIL_* OAUTH_GRAPH_* OAUTH_LINKEDIN_* OAUTH_REDIRECT_BASE=...

# White-Label-Branding (alles optional; leer = Standard-Worktide-Look)
BRAND_NAME=Meine Firma
BRAND_LEGAL_NAME=Meine Firma GmbH
BRAND_PRIMARY_COLOR=#0F8C72
BRAND_ACCENT_COLOR=#E0623A
BRAND_IMPRINT_URL=https://meine-firma.example.com/impressum
BRAND_PRIVACY_URL=https://meine-firma.example.com/datenschutz
# BRAND_LOGO_URL=https://cdn.example.com/logo.svg   # absolute URL; leer → siehe unten
# BRAND_LOGO_URL_DARK=https://cdn.example.com/logo-dark.svg
# BRAND_SUPPORT_EMAIL=support@meine-firma.example.com
```

**Eigenes Logo.** Setze entweder `BRAND_LOGO_URL` auf eine absolute URL, **oder**
lege eine Datei nach `var/branding/logo.svg` (auch `.png`/`.webp` möglich) — der
`var/`-Ordner ist ohnehin als Volume gemountet, das Logo wird dann unter
`{DEFAULT_URI}/branding/logo` ausgeliefert und funktioniert in E-Mails wie in
beiden Frontends ohne Rebuild. Ohne beides erscheint der Worktide-Schriftzug.
Farben, Logo und Impressum-/Datenschutz-Links werden von der API unter
`GET /v1/branding` bereitgestellt und von `worktide-web` **und** `worktide-portal`
zur Laufzeit übernommen — ein Ändern der ENV genügt, kein Frontend-Build nötig.

Zusätzlich für die DB-Container-Variablen eine `.env` (liest `docker compose` automatisch):

```dotenv
MYSQL_PASSWORD=__gleiches_pw_wie_in_DATABASE_URL__
MYSQL_ROOT_PASSWORD=__starkes_root_pw__
MERCURE_JWT_SECRET=__gleiches_secret_wie_in_.env.prod__
```

> **Sonderzeichen in Passwörtern** in `DATABASE_URL`/`MAILER_DSN` **URL-encodieren**
> (`@`→`%40`, `+`→`%2B`, `$`→`%24`, `/`→`%2F`).

---

## 5. Bauen & starten

```bash
docker compose -f compose.selfhosted.yaml build
docker compose -f compose.selfhosted.yaml up -d
```

Beim ersten Start:
- wartet `app` per `depends_on` auf die gesunde DB,
- erzeugt der Entrypoint das JWT-Keypair (falls fehlt),
- führt der `app`-Container die **Doctrine-Migrations** aus (`AUTO_MIGRATE=1`),
- holt FrankenPHP automatisch das **Let's-Encrypt-Zertifikat** für `SERVER_NAME`
  (DNS muss auf den Host zeigen, Ports 80/443 offen).

---

## 6. Verifizieren

```bash
docker compose -f compose.selfhosted.yaml ps          # alle services „healthy/running"
docker compose -f compose.selfhosted.yaml logs -f app # Boot, Migrations, FrankenPHP

curl -sS https://api.worktide.example.com/v1/          # API-Platform-Entrypoint (200/JSON)
```

Fehlersuche:
- `docker compose ... logs app` zeigt das **echte** Stdout (Migrations-Fehler, PHP-Fatals).
- `docker compose ... exec app php bin/console doctrine:migrations:status`
- `docker compose ... exec app php bin/console dbal:run-sql "SELECT 1"` — DB-Verbindung.

---

## 7. Reverse-Proxy (mehrere Domains auf einem Host)

Weil `app` und `mercure` beide 80/443 brauchen, setzt du bei einem Single-Host einen
Proxy davor (z.B. **Traefik** oder **Caddy**), der TLS terminiert und pro Host routet.
Dann geben `app` und `mercure` ihre Ports **nicht** direkt frei, sondern nur `expose`,
und `SERVER_NAME` wird auf `:80` gesetzt (Proxy macht TLS). Grobskizze mit Traefik:

```yaml
  proxy:
    image: traefik:v3
    command:
      - --providers.docker=true
      - --entrypoints.web.address=:80
      - --entrypoints.websecure.address=:443
      - --certificatesresolvers.le.acme.tlschallenge=true
      - --certificatesresolvers.le.acme.email=admin@example.com
      - --certificatesresolvers.le.acme.storage=/acme/acme.json
    ports: ["80:80","443:443"]
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - proxy_acme:/acme
```

und pro Service Labels `traefik.http.routers.<name>.rule=Host(...)` +
`...tls.certresolver=le`. `app`/`mercure` dann mit `SERVER_NAME: ":80"` und `expose: ["80"]`
statt `ports`.

---

## 8. Betrieb

- **Update/Release:** `git pull && docker compose -f compose.selfhosted.yaml up -d --build`
  (der `app`-Container migriert beim Boot automatisch).
- **Backups:** regelmäßiger DB-Dump, z.B. per Cron auf dem Host:
  `docker compose -f compose.selfhosted.yaml exec -T database mysqldump -uworktide -p"$MYSQL_PASSWORD" worktide | gzip > worktide-$(date +%F).sql.gz`
- **Scheduler-Cadences** in `frankenphp/crontab` anpassen.
- **Worker skalieren:** `docker compose -f compose.selfhosted.yaml up -d --scale worker=3`.
- **Meilisearch** (optional): eigenen Service ergänzen und `SEARCH_PROVIDER=meili` +
  `MEILISEARCH_DSN`/`MEILISEARCH_API_KEY` setzen.

---

## 9. Frontends (worktide-web, worktide-portal)

Beide sind Vite/React-SPAs → als statisches `dist/` via nginx ausliefern. In den
jeweiligen Repos liegen bereits `Dockerfile` + `nginx.conf`. Die `VITE_*`-Variablen
werden **beim Build** eingebacken:

```bash
# worktide-web
docker build -t worktide-web \
  --build-arg VITE_API_BASE=https://api.worktide.example.com/v1 \
  --build-arg VITE_API_PUBLIC_BASE=https://api.worktide.example.com/v1 \
  --build-arg VITE_MERCURE_HUB_URL=https://worktide-mercure.example.com/.well-known/mercure \
  --build-arg VITE_REFINE_DEVTOOLS=false .
docker run -d --restart unless-stopped --name worktide-web -p 8081:80 worktide-web

# worktide-portal
docker build -t worktide-portal \
  --build-arg VITE_API_BASE=https://api.worktide.example.com/v1 .
docker run -d --restart unless-stopped --name worktide-portal -p 8082:80 worktide-portal
```

Hinter denselben Reverse-Proxy hängen (Host `worktide.example.com` → `worktide-web:80`,
`kunden.example.com` → `worktide-portal:80`), damit auch sie TLS bekommen.

---

## 10. Häufige Stolpersteine

| Symptom | Ursache / Fix |
|---|---|
| `no available server` / 503 vom Proxy | App-Container nicht healthy/gestartet — `logs app` prüfen |
| App-Container startet, exitet nach Sekunden | `logs app` lesen: meist Migrations-Fehler (DB nicht erreichbar) oder fehlendes Secret (`APP_SECRET`, `JWT_PASSPHRASE`) |
| scheduler crasht ~alle 12s, „Failed to fork exec" | supercronic als PID 1 — `-no-reap` im command ergänzen (siehe scheduler-Service) oder `init: true` setzen |
| DB „not reachable" | Host in `DATABASE_URL` muss der Compose-**Servicename** `database` sein, nicht `localhost` |
| Kein TLS-Cert | DNS zeigt nicht auf den Host, oder Port 80 (ACME-Challenge) nicht offen |
| Uploads/Mail scheitern zur Laufzeit | `S3_*` / `MAILER_DSN` falsch (Sonderzeichen URL-encodieren) |
