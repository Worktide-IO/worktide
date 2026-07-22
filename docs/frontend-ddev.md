# Frontend-Entwicklung mit DDEV

## Übersicht

Beide Frontends (SPA und Portal) laufen als reduzierte DDEV-Projekte ohne
Datenbank. DDEV stellt SSL-Zertifikate und saubere Domains bereit.

| Frontend | Domain | Vite-Port |
|---|---|---|
| SPA | `https://worktide-web.ddev.site` | 5173 |
| Portal | `https://worktide-portal.ddev.site` | 5174 |

Beide Frontends sind **mit und ohne Port** erreichbar — nginx proxy
leitet Port 80 an Vite weiter. Vite startet automatisch via
`web_extra_daemons`. Die API wird vom Hauptprojekt `worktide`
bereitgestellt (muss ebenfalls laufen).

## Voraussetzung

Die Domains müssen in `/etc/hosts` eingetragen sein:
```
127.0.0.1 worktide-web.ddev.site worktide-portal.ddev.site
```

(DDEV versucht das automatisch, scheitert aber ohne sudo in manchen
Umgebungen. Einmaliger manueller Eintrag nötig.)

## Starten

```bash
# 1. Backend (API)
ddev start worktide

# 2. SPA
ddev start worktide-web

# 3. Portal
ddev start worktide-portal
```

Danach erreichbar:
- SPA:    `https://worktide-web.ddev.site` (auch `:5173`)
- Portal: `https://worktide-portal.ddev.site` (auch `:5174`)
- API:    `https://api.worktide.ddev.site`

## Konfiguration

### `.ddev/config.yaml` (beide Frontends identisch bis auf Port)

```yaml
name: worktide-web          # bzw. worktide-portal
type: php
docroot: ""
webserver_type: nginx-fpm
disable_settings_management: true
omit_containers: [db]
corepack_enable: true
nodejs_version: "22"

web_extra_exposed_ports:
  - name: vite
    container_port: 5173   # SPA; Portal: 5174
    http_port: 5172
    https_port: 5173       # Portal: 5174

web_extra_daemons:
  - name: "vite"
    command: bash -c 'CI=true corepack use pnpm@latest 2>/dev/null; CI=true pnpm install --frozen-lockfile --silent 2>/dev/null; CI=true pnpm dev'
    directory: /var/www/html
```

### Wichtige Details

- **`type: php` + `nginx-fpm`**: Erforderlich für DDEVs Healthcheck.
  `type: generic` würde den Healthcheck fehlschlagen lassen, da PHP-FPM
  für die Prüfung benötigt wird.
- **`omit_containers: [db]`**: Kein Datenbank-Container.
- **`corepack_enable: true`**: Macht `pnpm` verfügbar.
- **`web_extra_exposed_ports`**: Exponiert Vite-Port via ddev-router.
- **`web_extra_daemons`**: Startet Vite via Supervisor. Logs via
  `ddev logs -s web`.

## Troubleshooting

### Vite startet nicht

```bash
ddev logs -s web
ddev exec "ps aux | grep vite"
```

### Port-Konflikt

```bash
ddev exec "pkill -f vite" && ddev restart
```

### pnpm-Installation

```bash
ddev exec "cd /var/www/html && pnpm install"
```

### Healthcheck fehlschlagen

DDEV erwartet PHP-FPM + lesbares `/var/www/html`. Ein Dummy-Index sollte
vorhanden sein oder `docroot` muss auf ein existierendes Verzeichnis
zeigen.
