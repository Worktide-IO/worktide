# OAuth-Setup für Mail-Channels (Phase C.5)

Worktide spricht Microsoft 365 (über Microsoft Graph) und Google Workspace
(über Gmail API) per OAuth2. Bevor ein Workspace solche Channels nutzen
kann, braucht es entweder eine **Worktide-globale App-Registrierung**
oder eine **per-Workspace-App**. Default ist die globale App.

## Microsoft Graph (Adapter `email_graph`)

1. Im Azure Portal → **Microsoft Entra ID → App-Registrierungen → Neue Registrierung**.
2. Name: `Worktide (Production)` o. ä.
3. Unterstützte Kontotypen: *Konten in einem beliebigen Organisationsverzeichnis (Beliebiges Microsoft Entra ID-Verzeichnis — Mehrinstanzenfähig)*.
4. Umleitungs-URI: `Web` → `https://api.<deine-domain>/v1/channels/oauth/callback`.
5. Nach Erstellung:
   - **Übersicht** → `Anwendungs-ID (Client)` notieren → `OAUTH_GRAPH_CLIENT_ID`.
   - **Zertifikate & Geheimnisse** → Neues Client-Geheimnis → Wert notieren → `OAUTH_GRAPH_CLIENT_SECRET`.
6. **API-Berechtigungen** → Microsoft Graph → *Delegierte Berechtigungen*:
   - `Mail.Read`
   - `Mail.Send`
   - `Mail.ReadWrite`
   - `User.Read`
   - `offline_access` (für Refresh-Token)
7. Admin-Zustimmung erteilen (für deinen Tenant — Mehrinstanzen-Tenants
   bekommen die Zustimmung beim ersten Login pro Tenant separat).

## Google Workspace (Adapter `email_gmail`)

1. Google Cloud Console → **APIs & Services → OAuth-Zustimmungsbildschirm**.
2. Benutzertyp: *Extern* (oder *Intern* für Workspace-only).
3. Scopes hinzufügen:
   - `https://www.googleapis.com/auth/gmail.readonly`
   - `https://www.googleapis.com/auth/gmail.send`
   - `https://www.googleapis.com/auth/gmail.modify`
   - `https://www.googleapis.com/auth/userinfo.email`
4. **APIs & Services → Anmeldedaten → OAuth-Client-ID erstellen** (Typ:
   *Webanwendung*).
5. Autorisierte Weiterleitungs-URIs:
   `https://api.<deine-domain>/v1/channels/oauth/callback`.
6. Nach Erstellung:
   - `Client-ID` → `OAUTH_GMAIL_CLIENT_ID`
   - `Client-Geheimnis` → `OAUTH_GMAIL_CLIENT_SECRET`
7. **Gmail API aktivieren** im API-Library-Tab.

## Worktide `.env`

```bash
OAUTH_GRAPH_CLIENT_ID=<aus Azure>
OAUTH_GRAPH_CLIENT_SECRET=<aus Azure>
OAUTH_GMAIL_CLIENT_ID=<aus Google Cloud>
OAUTH_GMAIL_CLIENT_SECRET=<aus Google Cloud>

OAUTH_REDIRECT_BASE=https://api.<deine-domain>
SPA_BASE_URL=https://<deine-domain>
```

Nach `cache:clear` ist die globale App aktiv und jeder Workspace-Admin
kann im Worktide-Channel-Dialog auf "Mit Microsoft anmelden" /
"Mit Google anmelden" klicken — der OAuth-Flow läuft komplett im
Browser des Admins.

## Per-Channel-Override (optional)

Wenn ein Workspace seine eigene App registriert hat (z. B. weil der
Compliance-Tenant es verlangt), kann der Admin im Channel-Edit-Dialog
unter "OAuth-App" eigene `clientId` + `clientSecret` einkleben. Der
`OAuth2AppCredentialsResolver` bevorzugt diese vor der globalen App.

## Testen

Lokales DDEV hat keine echten Azure/Google-Anmeldedaten. Für einen
End-to-End-Test:

1. Echte Test-Apps registrieren (siehe oben).
2. `.env.local` mit den Credentials befüllen.
3. Channel im Workspace anlegen (`adapterCode=email_graph`).
4. "Mit Microsoft anmelden" klicken → Consent geben → zurück nach
   `/inbox?oauth=ok`.
5. `bin/console worktide:channel:pull --channel=<uuid>` zieht den ersten
   Batch.
6. Im Worktide-UI eine Antwort auf einen Thread schreiben — das
   OutboundMessage geht über Graph raus.

Refresh-Token-Rotation passiert automatisch — `OAuth2Client::ensureAccessToken()`
prüft die Ablaufzeit und fragt bei < 60 s ein neues Access-Token an,
bevor jede API-Call.
