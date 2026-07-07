# Worktide Security Model

Stand: 2026-06-16

Dieses Dokument beschreibt das **Sitzungs- und Auth-Modell** sowie die
**Schutzgrenzen** in Worktide. Es ist die einzige normative Quelle für
Session-Lifetime, Token-Rotation und Sicherheitseinstellungen — wenn
Code und dieses Dokument widersprechen, gewinnt das Dokument.

## Architektur in einem Bild

```
                   ┌────────────────────────────────────────────┐
                   │  POST /v1/auth/login                       │
                   │   → JWT (access, kurzlebig)                │
   ┌──────────┐    │   → Refresh-Token (langlebig)              │
   │ Browser  │───→│                                            │
   └──────────┘    │  Jeder API-Call:  Authorization: Bearer …  │
        ↑          │  Bei 401:         POST /v1/auth/refresh    │
        │          └────────────────────────────────────────────┘
        │
        │  401 nach Refresh-Fail   →  Redirect /login
        │
```

Keine klassischen Server-Sessions. Authentifizierung ist stateless via
JWT, Sitzungslebensdauer wird über das Verhältnis Access-TTL ⇄ Refresh-
TTL gesteuert.

## Die drei Stellschrauben

### 1. Access-Token-TTL — wie lange ein einzelner Bearer-Token gilt

| Quelle | Wert | Wirkung |
|---|---|---|
| `config/packages/lexik_jwt_authentication.yaml` → `token_ttl` | **3600 s (1 h)** | Default für alle Workspaces |
| `Workspace.settings.sessionTtl.access` | int (60–3600), nullable | **Pro Workspace**, gilt nur falls **kleiner** als der globale Default. Nie weiter. |

**Konflikt-Auflösung** bei Multi-Workspace-Mitgliedschaft: Beim
JWT-Erstellen läuft `JwtWorkspaceTtlSubscriber` über alle Workspaces des
Users und nimmt den **kleinsten** Wert. Damit kann ein Account in einem
gehärteten Workspace nicht durch Mitgliedschaft in einem laxen
Workspace aufgeweicht werden.

### 2. Refresh-Token-TTL — wie lange die "Session" insgesamt lebt

| Quelle | Wert | Wirkung |
|---|---|---|
| `config/packages/gesdinet_jwt_refresh_token.yaml` → `ttl` | **2 592 000 s (30 Tage)** | Global |
| `ttl_update: true` | Sliding | Jeder Refresh setzt den Zähler zurück. Wer aktiv ist, bleibt aktiv. |

**Nicht** workspace-überschreibbar in V1. Gesdinet verdrahtet die TTL
als Service-Constructor-Argument; ein Runtime-Override wäre ein
größerer Umbau als der Nutzen rechtfertigt. Die Workspace-Settings-UI
zeigt das transparent: der Refresh-TTL-Wert ist read-only mit Hinweis
auf den Yaml-Pfad.

### 3. Browser-seitige Persistenz — wo die Tokens im Browser leben

| User-Wahl bei Login | Storage | Lebensdauer |
|---|---|---|
| ☑ "Angemeldet bleiben" (Default) | `localStorage` | überlebt Tab-Schließen + Browser-Neustart |
| ☐ unchecked | `sessionStorage` | endet beim Tab-/Browser-Schließen |

Implementiert über die Helper `readAuth/writeAuth/clearAuth` in
`src/lib/api.ts` plus das Persistenz-Flag `wt.remember` in
`localStorage`. Reader fallen über sessionStorage **dann** localStorage,
Writer schreiben nur in den gewählten Bucket und räumen den anderen.

## User-Controls

Im `/settings/profile` → Tab **Sicherheit**:

- **Aktive Sitzungen-Liste** mit Browser/OS-Label, IP, Letzte Aktivität,
  "diese Sitzung"-Badge und Einzel-Revoke pro Zeile + Bulk-Revoke ("Alle
  anderen abmelden"). Daten kommen aus `GET /v1/me/sessions`.
- **Auto-Logout bei Inaktivität** Dropdown (Nie / 5 / 15 / 30 / 60 / 120
  Min). Persistiert in `UserPreferences.idleTimeoutMinutes`. Enforced
  client-seitig via `useIdleLogout`-Hook der auf User-Input lauscht und
  bei Überschreitung der Schwelle `authProvider.logout()` ruft.

## Workspace-Admin-Controls

Im `/settings/workspace` → Card **Sicherheit**:

- **Access-Token-Lifetime** Input (60–3600 s). Leer = inherit Lexik
  default. Gespeichert in `Workspace.settings.sessionTtl.access`.
- **Refresh-Token-Lifetime** read-only Anzeige der globalen 30 Tage.

PATCH ist hinter dem `WorkspaceVoter` (EDIT) → nur Workspace-Admins
schreiben erfolgreich, andere kriegen 403 + Toast.

## Revocation

| Methode | Was passiert sofort | Was passiert verzögert |
|---|---|---|
| `DELETE /v1/me/sessions/{id}` | Refresh-Token-Row entfernt | Der laufende Access-Token wird **erst beim Ablauf** ungültig (≤ 1 h, je nach Workspace-TTL kürzer) |
| `POST /v1/me/sessions/revoke-others` | Alle Refresh-Tokens außer dem aktuellen entfernt | dito |
| `POST /v1/auth/logout` (Self-Logout) | Refresh-Token-Row entfernt + localStorage gewiped | Access-Token kann theoretisch noch bis Ablauf benutzt werden, ist aber im Browser nicht mehr verfügbar |

**Warum kein sofortiger Server-Side-Kill?** Eine Denylist auf
JWT-`jti`-Basis würde jeden Request mit einem Cache-Lookup belasten —
der Gewinn (max 1 h Schadensfenster) rechtfertigt den Verlust der
Statelessness nicht. Wenn das nötig wird, ist der vorgesehene Weg:
Access-TTL über Workspace-Setting auf 5–15 min senken, nicht eine
Denylist einbauen.

## Rate-Limits

Alle öffentlichen (unauthentifizierten) Endpunkte sind gegen
Brute-Force / Missbrauch abgesichert. Limiter-Definitionen im
`rate_limiter`-Block von `config/packages/framework.yaml`, verdrahtet
in `config/services.yaml`.

Auth-nah — `src/EventSubscriber/AuthRateLimitSubscriber.php`:

- `POST /v1/auth/login` — Symfony `login_throttling`: 5 Versuche /
  Minute pro (IP + E-Mail) (siehe `config/packages/security.yaml`).
- `POST /v1/auth/refresh` — Limiter `auth_refresh`.
- `POST /v1/auth/forgot-password` — Limiter `auth_forgot_password`.
- `POST /v1/auth/reset-password` — Limiter `auth_reset_password`.
- `POST /v1/me/password` — Limiter `auth_password_change`.
- `POST /v1/forms/{slug}` (Absenden) — Limiter `public_form_submit`
  (in `PublicFormController`).

Übrige öffentliche Endpunkte — `src/EventSubscriber/PublicEndpointRateLimitSubscriber.php`,
alle pro Client-IP:

- `POST /v1/workspace_invitations/{token}/accept` (und künftige
  Kunden-Einladungs-Accepts) — Limiter `public_token_accept`
  (10 / 15 min). Token-als-Credential; Schutz gegen Token-Raten.
- `POST|PUT /v1/inbound/(entity-)webhooks/{token}` — Limiter
  `inbound_webhook` (240 / min, großzügig, damit legitime
  Provider-Bursts durchgehen; pro Deployment anpassbar).
- Anonyme Lese-/Redirect-Endpunkte (`GET /v1/branding`, `/v1/setup/*`,
  `GET /v1/forms/{slug}`, `/v1/social/media/*`,
  `/v1/channels/oauth/callback`) — Limiter `public_anonymous`
  (120 / min) gegen Scraping / Enumeration / Amplification.

Signierte Tokens (`social/media`, OAuth-`state`) sind bereits durch
ihre Signatur gegen Raten geschützt; der Limiter ist hier
Defense-in-Depth.

Bei Throttle wird die Response auf **429** (mit `Retry-After`) angehoben
(`AuthFailureStatusSubscriber` bzw. `TooManyRequestsHttpException`) —
das default-401 von Lexik bei Login-Throttling würde sonst irreführend
"ungültige Zugangsdaten" anzeigen.

## Auth-Audit-Log

Jedes Auth-Ereignis landet als Domain-Event in `domain_event_log`:

- `auth.login.succeeded` — payload: email, ip, userAgent
- `auth.login.failed` — payload: username, ip, userAgent, reason
- `auth.logout` — payload: ip, userAgent
- `auth.password.changed` — payload: ip, userAgent

Quelle: `AuthAuditSubscriber.php`. Sichtbar unter `/activity` mit
entsprechendem Voter-Schutz.

## Password-Policy

`src/Service/PasswordPolicy.php`:

- min. 10 Zeichen
- mindestens 3 der 4 Klassen: Kleinbuchstaben, Großbuchstaben, Ziffern,
  Sonderzeichen
- Blocklist (häufige Passwörter, Username-Echo)

Angewendet auf `POST /v1/me/password`. Login akzeptiert beliebige
Strings damit Legacy-Passworte durch den Login passen und der User dann
über die Profil-Seite ein neues setzen kann.

## Was nicht implementiert ist (Phase F)

- **2FA / TOTP** — geplant via `scheb/2fa-bundle`
- **Passkeys / WebAuthn** — geplant via `web-auth/webauthn-symfony-bundle`
- **SAML SSO + SCIM Provisioning** (Keycloak / Azure-AD)
- **Account-Lockout nach N Fehlversuchen** (über das aktuelle Rate-Limit
  hinaus)
- **Sofortige JWT-Revocation per `jti`-Denylist** — siehe Abwägung oben

Siehe `ROADMAP.md` → Phase F.
