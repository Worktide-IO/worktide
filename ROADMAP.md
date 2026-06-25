# Worktide Roadmap

Stand 2026-06-25. Konsolidierte Roadmap aus Inspiration durch awork, Redmine (via bluemine), Asana, Jira und FreeScout.

## Bereits gebaut

### Backend (`worktide`)
- Foundation: Workspace / Project / Task / TimeEntry, JWT-Auth, Voters, UUIDv7
- B1–B11 + Sweep: Comments, ChecklistItems, TaskDependency, polymorphe File-Attachments + Versioning, Project- + Task-Templates, Workflow + Automation, Workforce (Teams, Absences, UserCapacity, TypeOfWork), Reports + Autopilot, Documents (B9), outbound HMAC-signed Webhooks, Permission-Matrix mit Per-Workspace-Overrides, PersonalAccessTokens, Workspace-Invitations, ActiveTimer, TimeTrackingSettings, BatchOperations
- CRM-1 + CRM-2: Customer + Contact + CustomerSystem + ServiceSubscription mit nextBillingOn-Auto-Compute
- Mercure-Realtime auf 19 Entities
- Watcher (polymorph: Project / Task / Document)
- TaskAssignee polymorph (User OR Team)
- ImportController (CSV → customers / contacts / tasks)
- UserPreferences (Dashboard-Layout-Persistierung)
- MeProfileController (Profile-PATCH + Password-Change mit Strength-Policy)
- Auth-Härtung: Login-Throttling, Rate-Limit auf Refresh + Password-Endpoints, Auth-Audit ins DomainEventLog

### SPA (`worktide-web`)
- React 19 + Refine 5 + Tailwind v4 + shadcn + JWT
- CRUD-Pages: Customers (mit Detail-Tabs Übersicht/Kontakte/Systeme/Abos), Contacts, Projects (mit Detail + Kanban-Board mit DnD), Tasks, TimeEntries, Customer-Systems, Service-Subscriptions
- **The Wall**: Workspace-weites Team-Dashboard mit Lanes nach ProjectStatus
- **Konfigurierbares Widget-Dashboard** (react-grid-layout, persistiert via UserPreferences)
- **Floating Mini-Timer** (global, auf jeder Seite)
- Avatar-Stack-Komponente
- **Activity-Feed** (`/activity`)
- **Saved Queries** (TaskView-Wrapper auf Tasks-Liste)
- **Bulk-Edit-Toolbar** mit Status / Prio / Löschen
- **CSV-Import-Wizard** (3-Step)
- Profile- + Workspace-Settings
- **WatchButton** für Projekte (analog für Tasks/Documents möglich)

---

## Phase A — Frontend-Polish

**Ziel:** Was bereits gebaut ist, vollständig sichtbar und benutzbar machen. Kein neues Backend-Konzept.

- Dashboard-Widgets ersetzen die Platzhalter:
  - **ActiveTimer-Widget** (große Stoppuhr + Heute-Summe + Quick-Start)
  - **"Alle offenen Kunden-Aufgaben"** (cross-project Liste)
  - **"Meine Aufgaben"** mit Tabs Heute / Diese Woche / Überfällig
- Sidebar-Polish:
  - ~~Pinned / Recent Projects unter "Meine Projekte"~~ — **erledigt** (Sidebar-Favoriten)
  - Sammelprojekte vs Kunden-Projekte Gruppierung
- ~~**Quick-Add Cmd+K Popover** — globaler Shortcut, Task in Sekunden anlegen~~ — **erledigt** (QuickAddDialog: Cmd+K, Task + Projekt)
- ~~**Calendar-View** — FullCalendar-React, Tasks mit dueOn als Events~~ — **erledigt** (`/calendar`)
- ~~**Globale Suche** — cross-resource Suche (Tasks, Projects, Customers, Contacts, Documents)~~ — **erledigt** (GlobalSearchDialog, Cmd+/)
- **Smart-Links** — externe URLs als Rich-Cards (oEmbed: YouTube, Figma, Confluence, …)
- **Status-Updates** — strukturierte Projekt-Berichte (was läuft, Risiken, nächste Schritte)
- ~~**Top-Level-Routes** ausbauen: Kalender, Planer, Personen, Auswertungen~~ — **erledigt** (alle vier als Routen vorhanden)

---

## Phase B — Issue-Tracking-Architektur

**Ziel:** Worktide vom Task-Manager zum Issue-Tracker upgraden — Jira-Pendant.

### Schicht 1 — Datenmodell
- ~~**Trackers** (Bug / Feature / Story / Support) als eigene Entity, Task bekommt FK zu Tracker. M:N zu CustomFields.~~ — **erledigt** (Backend, B-Sweep)
- ~~**Versions / Releases** mit `sharing`-Enum (none/descendants/hierarchy/tree/system), `effectiveDate`, Status open/locked/closed, optional Wiki-Page pro Version. Tasks bekommen `fixedVersion`.~~ — **erledigt** (ProjectVersion)
- ~~**IssueRelation-Typen** erweitern: aktuell 1 Typ, ausbauen auf `blocks`, `duplicates`, `relates`, `follows`, `precedes` + `delay`-Spalte für Scheduling.~~ — **erledigt** (TaskDependency-Typen)

### Schicht 2 — Workflow-Engine
- ~~**Workflow-per-Tracker × Status × Role**: WorkflowTransition + WorkflowPermission. Wer darf welchen Status-Wechsel auslösen, welche Felder sind in welchem Status editierbar.~~ — **erledigt** (Backend; SPA prüft Transitions im Board client-seitig vor)
- **Visueller Workflow-Editor** (Drag-Drop, ähnlich Asana Workflow Builder). — offen (Frontend)

### Schicht 3 — Reporting
- ~~**Reports SPA-UI mit Charts** (Recharts)~~ — **erledigt** (Phase B.3b/B.3c): Tabs unter `/auswertungen` für Zeit, Burndown, Created-vs-Resolved, Cycle-Time, MRR und **Cumulative Flow** (Status-Bänder pro Tag via DomainEventLog-Replay). Workload als Overlay im Team-Planner.
- ~~**Velocity** (abgeschlossene Arbeit pro Sprint)~~ — **erledigt** (Phase B.4.2): `GET /v1/reports/velocity` + Velocity-Chart auf `/sprints`, Größe via `estimatedMinutes`. Story-Points als optionales Maß später möglich.
- **Konfigurierbare Custom-Dashboards** (Drag-Drop, pro Workspace persistiert) — offen, über die festen Report-Tabs hinaus.

### Schicht 4 — Erweiterte Views
- ~~**Workload-View** (Visualisierung pro User: gebuchte Stunden vs UserCapacity vs Absences)~~ — **erledigt**: als WorkloadOverlay im Team-Planner (`/v1/reports/workload`)
- ~~**Sprints / Backlog**: startDate / endDate / Sprint-State, Velocity, Burndown~~ — **erledigt** (Phase B.4.2): `Sprint`-Entity (projekt-scoped) + `Task.sprint`, `/sprints`-Board mit Backlog + Sprint-Spalten (DnD), Sprint-Burndown (`?sprint=`) + Velocity-Chart
- ~~**Public Forms**: öffentliche `/forms/<slug>` Endpunkte, generieren Tasks aus Submissions mit Custom-Fields-Mapping~~ — **erledigt** (Backend): `PublicForm` + `PublicFormSubmission`-Entities (Admin-CRUD unter `/v1/public_forms`), öffentliche `GET/POST /v1/forms/{slug}` (PUBLIC_ACCESS), Submission → Task im Ziel-Projekt mit nativem (title/description/priority) + `cf:<key>`-Custom-Field-Mapping, Honeypot + Per-IP-Rate-Limit, Audit-Record. Form-Builder-UI + Public-Renderer offen (SPA-Repo)

---

## Phase C — Helpdesk + Mail-Integration

**Ziel:** Inbound-Mail wird zu Tasks/Conversations. Outbound-Mail aus Worktide. Brücke zur KI-Schicht.

### Schicht 1 — Mailbox-Layer (FreeScout-inspiriert)
- **Mailbox-Entity** workspace-scoped: Name, IMAP/SMTP/OAuth-Config, Signature, Auto-Reply, isShared
- **Auth-Verfahren pro Mailbox** wählbar:
  - **SMTP + IMAP mit Passwort** (Generic, App-Passwords für 2FA-Provider)
  - **OAuth Microsoft 365 / Exchange Online** via Microsoft Graph — sowohl delegierte (User-Account) als auch Application-Permissions (Service-Mailbox). Scopes: Mail.Read, Mail.Send, Mail.ReadWrite. Refresh-Worker erneuert Tokens vor Ablauf.
  - **OAuth Google Workspace** via Gmail API
- Tokens encrypted-at-rest (libsodium via Symfony Secrets)
- **Mailbox-Sync-Worker** via Symfony Messenger (IMAP-IDLE / Graph-Webhooks / Polling als Fallback)
- **Mehrfach-Email** pro User und pro Contact: `EmailAddress(owner, address, isPrimary, isVerified)`

### Schicht 2 — Threading
- **Conversation-Entity**: subject, customer (auto-resolved via from-email), assignee, status (Active/Pending/Closed/Spam), mailbox
- **Thread-Entity** mit `type: customer | message | note | forward`, body, attachments, `in-reply-to`, `message-id`, Headers-JSON
- **Internal Notes** als Thread-Type `note` — privat, mit @-Mentions
- **Forwarding** als Thread-Type `forward`
- **Saved Replies** workspace-scoped, mit Variablen-Interpolation

### Schicht 3 — Collaboration
- **Collision Detection** via Mercure-Presence — Hinweis wenn 2 User dieselbe Conversation öffnen
- **Auto-Reply pro Mailbox** (Empfangsbestätigung)
- **Phone-Conversation** (manuelles Ticket für Telefonate)

### Schicht 4 — Routing + Conversion
- Auto-Resolve: Eingehende Mail → Contact via from-email → Customer + Projekt-Kontext
- 1-Klick "Aus Konversation Task anlegen"
- Inbound-Webhook für Mail-Provider mit Webhook-API (SendGrid, Mailgun, Resend)

---

## Phase D — KI-Integration / Digitaler Projektmanager

**Ziel:** Worktide wird vom Verwalter zum aktiven Vorschlager.

### Schicht 1 — Infrastruktur
- **`AIRecommendation`-Entity**: suggestion, reasoning (Markdown), appliesTo polymorph (Task/Project), status (pending/accepted/rejected), source
- **`LlmProviderInterface`** + Anthropic-Claude-Implementierung (default) + Ollama-Adapter für datenschutzsensible Workspaces
- Prompt-Caching für wiederkehrende Workspace- + User-Kontexte

### Schicht 2 — Aufwands-Schätzung
- AI schlägt `estimatedMinutes` vor — basierend auf TimeEntry-History ähnlicher Tasks (gleiches Projekttyp / Customer / Tags)
- Lern-Schleife: bei Task-Close vergleicht Schätzung vs Ist, kalibriert das per-Workspace-Modell

### Schicht 3 — Auto-Scheduling
- Aus (Prio + Schätzung + Deadline + Dependencies + UserCapacity + Absences) → Vorschlag wann/wer
- Planungs-Ansicht zum Akzeptieren / Ändern

### Schicht 4 — Mail + Outbound
- AI klassifiziert Conversations (Anfrage / Beschwerde / Antwort / Newsletter / …) und priorisiert
- Reply-Suggestions im Conversation-Editor — nutzt Saved Replies als Few-Shot-Beispiele
- Automatische Status-Updates an Kunden bei Conversation-Closed

### Schicht 5 — Smart Features
- "Diese Aufgabe in Subtasks aufbrechen" (AI-Breakdown)
- Natural-Language-Search → API-Filter-Generierung

---

## Phase D⁺ — Such-Service (optional)

**Ziel:** Skalierbare Volltextsuche sobald die MySQL-`LIKE`-Variante an ihre Grenzen stößt. Vor Phase C (Mail-Bodies) selten gerechtfertigt; danach typischerweise mit dem ersten 100k+-Workspace fällig.

**Wann lohnt es sich?**
- Mail-Bodies / Conversation-Threads sollen volltext-durchsucht werden mit Ranking + Highlighting
- Typo-Toleranz + "did you mean" + Facetten (Status / Priority / Customer) im Such-Dropdown
- Workspaces mit 100k+ Tasks oder Conversations — `LIKE '%…%'` skaliert nicht, MySQL FULLTEXT-Index nur eingeschränkt brauchbar (kein Ranking, kein Fuzzy)

**Architektur**
- **`SearchProviderInterface`** in der globalen Suche: `MysqlSearchProvider` bleibt Default, `MeilisearchSearchProvider` / `TypesenseSearchProvider` als Drop-in
- **Indexer** via Symfony Messenger: Doctrine-Lifecycle-Events (`postPersist` / `postUpdate` / `postRemove`) feuern `IndexDocument`-Messages — kein synchroner Pfad, damit Schreibvorgänge nicht blockieren
- **Reindex-Command** für Bootstrap + Schema-Migrationen: `worktide:search:reindex --resource=tasks,conversations`
- **Per-Workspace-Toggle** in den Workspace-Settings: standard MySQL, Aktivierung schaltet auf Meilisearch um (Tenant-Isolation via Index-Pro-Workspace oder per-Workspace-Filter)
- **Self-hostable**: Meilisearch oder Typesense (beide MIT-lizensiert, eine Binary, kein Cluster-Overhead) — kein Lock-in via Elasticsearch / Algolia

**Migration**: Phase A's globale Suche (Cmd+/) bleibt funktional — sie kriegt einfach einen anderen Provider hintergeklemmt. Frontend ändert sich nicht.

---

## Phase E — CRM + Customer-Portal

**Ziel:** CRM-3 + CRM-4 abschließen, Kunden bekommen ihre eigene Sicht.

- **CRM-4 Invoice + Billing-Run**: Invoice + InvoiceItem-Entity, Cron der aus ServiceSubscription (`nextBillingOn ≤ heute`) Rechnungen materialisiert. Status-FSM draft/sent/paid/cancelled
- **Lexoffice OAuth-Integration**: OAuthConnection + Mapping `lexoffice_contact_id ↔ worktide_customer_id`. Auto-Push bei `invoice.created`
- **Document-Vault**: Rechtssicherer File-Store, verschlüsselte Storage (S3-SSE), Versionierung, Retention-Policies (GoBD), PDF-Volltextsuche, Audit-Log
- **TYPO3-Customer-Portal** (`wapplersystems/worktide-customer-portal`): Extbase-Plugin gegen Worktide-API. Kunden sehen ihre CustomerSystems, ServiceSubscriptions, Wartungs-Reports, Rechnungen
- **Terminvereinbarung** (Calendly-Klon): BookingPage, MeetingType, Availability, Booking, ExternalCalendarSync mit Google/Outlook. Public `/book/<slug>`
- **Themability**: Light/Dark, Per-Workspace-Branding (Primary-Color + Logo), Custom-Theme-Builder

---

## Phase F — Enterprise-Features

**Ziel:** Worktide reif für Agenturen mit 50+ Sitzen und Compliance-Anforderungen.

### Identity
- **SAML SSO + SCIM Provisioning** (Keycloak / Azure-AD), per-Workspace konfigurierbar, JIT-Provisioning
- **TOTP-2FA + Backup-Codes** (`scheb/2fa-bundle`)
- **Passkeys / WebAuthn** (`web-auth/webauthn-symfony-bundle`)
- ~~**Active-Sessions-Liste** + "Sign out all other devices"~~ — **erledigt** (siehe [SECURITY.md](SECURITY.md))
- ~~**Per-Workspace Access-Token-TTL**~~ — **erledigt** (siehe [SECURITY.md](SECURITY.md))
- ~~**Auto-Logout bei Inaktivität (pro User)**~~ — **erledigt** (siehe [SECURITY.md](SECURITY.md))
- ~~**"Auf diesem Gerät angemeldet bleiben" Login-Checkbox**~~ — **erledigt** (siehe [SECURITY.md](SECURITY.md))
- **Account-Lockout** nach N fehlgeschlagenen Versuchen
- **Sofortige JWT-Revocation** (jti-Denylist) — bewusst nicht in V1, siehe Abwägung in [SECURITY.md](SECURITY.md)

### Permissions
- **Permission Schemes + Issue Security Schemes**: Sichtbarkeit pro Projekt/Workspace, Per-Task-Sichtbarkeit
- **Notification Schemes**: Event → Empfänger-Mapping
- **API-Token-Scopes**: PAT mit `read | write | admin | webhook`-Granularität

### Audit + Compliance
- **Audit-Log SIEM-Export** (Splunk / Datadog / Loki)
- **Data Residency Option** (Workspace-Storage in Region)
- **OAuth-Server**: Worktide AS OAuth2-Provider für externe Apps (z.B. TYPO3-Portal)

---

## Phase G — Plattform + Strategische Investments

**Ziel:** Worktide wird Plattform. Post-6-Monate, bedarfsgetrieben.

- **Mobile Native App** (Flutter, separates Repo)
- **App-Marketplace / Plugin-System** über OAuth + Webhooks
- **Goals / OKRs**: verschachtelte Company/Team/Personal-Ziele
- **Portfolios**: Sammlung von Projekten mit Status-Rollup
- **Approvals + Proofing**: Approval-Task-Typ, Inline-Annotationen auf Bildern/PDFs
- **Gantt-DnD-Editor** (frappe-gantt oder bryntum-gantt)
- **Notification-Preferences**: sofort / verzögert / digest / DnD-Fenster, pro Channel separat (Email / Mercure / Mobile / Browser-Push)
- **AI Studio + AI Teammates**: persistente AI-Agenten als Task-Assignees
- **Multiple Sandboxes** (Test-Environment parallel zum Produktiv-Workspace)
- **Repository-Integration**: Git/GitLab/GitHub Branch + PR-Sicht im Task, Smart-Commit-Syntax

---

## Kritische Entscheidungspunkte

- **Vor Phase B**: Will Worktide explizit gegen Jira konkurrieren oder reicht Tracker-Light? Im Light-Fall: Trackers + Versions reichen, Workflow-per-Tracker streichen.
- **Vor Phase D**: AI als User-facing-Vorschlag oder Hidden-Boost (Schätzungen automatisch übernehmen, unsichtbar)?
- **Vor Phase F**: Erste Enterprise-Kunden-Anfrage abwarten, vorher keine SSO/2FA/Sandboxes.
- **Vor Phase G**: Marketplace und OAuth-Server erst wenn 50+ aktive Workspaces produktiv.

---

## Empfohlene Reihenfolge

1. **Phase A** — Frontend-Polish: erst sichtbar machen was schon da ist
2. **Phase C** — Mail-Integration: größter Workflow-Hebel + Brücke für KI
3. **Phase B** — Issue-Tracking-Architektur (kann parallel zu C laufen, weil getrennte Schichten)
4. **Phase D** — KI: hängt an Phase-C-Daten
5. **Phase D⁺** — Such-Service (Meilisearch / Typesense): optional, erst zünden wenn MySQL-`LIKE` nicht mehr reicht (Mail-Bodies oder >100k Datensätze)
6. **Phase E** — CRM-Vervollständigung + Customer-Portal (kann parallel zu D laufen)
7. **Phase F** — Enterprise: bedarfsgetrieben nach erstem Enterprise-Kunden
8. **Phase G** — alles andere bei Bedarf

---

## Bewusste Ablehnungen

- **Native Mobile vor PWA-Übergang** — Web-PWA mit Service-Worker + Push reicht für 80% der Use-Cases
- **JQL-DSL** (Jira-eigene Query-Sprache) — API-Platform-Filter sind ausdruckstark genug
- **Components (Jira-Style)** — Tag-System deckt 80% ab
- **Screen Schemes (Jira)** — Workflow-per-Tracker-Pattern reicht
- **Marketplace vor 50+ Production-Workspaces** — Lock-in-Risiko zu hoch ohne Validation
- **Eigener Email-Server** — auf SES/Postmark/MS-Graph/Gmail-API setzen

---

## Was Worktide besser als Asana und Jira macht

- **Multi-Tenancy** seit Tag 1 — beide Wettbewerber sind Single-Workspace
- **CRM-Integration nativ** (Customer / Contact / CustomerSystem / ServiceSubscription) — fehlt komplett bei Asana und Jira
- **Time-Tracking nativ** + Floating-Timer — Jira braucht Tempo-Plugin, Asana nur ab Advanced-Tier
- **Mercure-Realtime durchgängig** — beide Wettbewerber arbeiten polling-basiert
- **Watcher polymorph** über Task / Project / Document — Jira nur auf Issues
- **The Wall** — Live-Team-Status auf einem Bildschirm, fehlt bei beiden in dieser Form
- **API-First mit JSON-LD** — Hypermedia-Vertrag von Anfang an
