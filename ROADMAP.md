# Worktide Roadmap

Stand 2026-07-15. Konsolidierte Roadmap aus Inspiration durch awork, Redmine (via bluemine), Asana, Jira und FreeScout.

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
- **Research-/Akquise-Agent**: Missions-Liste + konversationelle Freitext-Erstellung mit Rückfrage-Dialog, Lead-Pipeline (Stage-Wechsel, In-Kunde-Umwandlung), Lead-Aktivitäts-Timeline (+ manuelle Notizen), Vorschlags-Inbox
- **KI-Agenten-Übersicht** (`/ki-agenten`): Empfehlungs-Inbox (Accept/Reject/Filter) + Trigger für Marketing-Draft, Upgrade-Outreach und „Verteilung planen"

### KI-Agenten, Research/Akquise & universelles Agent-Action-Fundament
Realisiert die Phase-D-Infrastruktur und macht sie generisch (Details in den Phasen D / D⁺):
- **Human-in-the-Loop-Seam** produktiv: `AIRecommendation` (polymorph target/kind/status/suggestion) + `RecommendationApplier` + Accept/Reject-Endpoints; `LlmProviderInterface` (Anthropic default / Infomaniak) mit `completeJson`; eigener `ai_agents`-Messenger-Transport; `EgressGuard`/`EgressModule` (default-deny) als Outbound-Gate über alle Kanäle.
- **Agenten**: Ticket-Triage, Ticket-aus-Konversation, Marketing-Social-Draft, Customer-Upgrade-Outreach, **Research/Akquise** (Missionen + Rückfragen + externe Suche Tavily/BuiltWith + Lead-Extraktion, proaktive Vorschläge), **Distribution-Planner**.
- **Universelles Agent-Action-Fundament**: statt eines bespoke Stacks pro Fähigkeit erarbeitet der LLM die Empfehlungen selbst (`AgentActionPlanner` über einen `CapabilityCatalog` aus den verbundenen Kanälen), und ein **einziger** generischer Applier-Zweig führt sie aus — Dispatch nach Archetyp (`social_post` / `outbound_message`), Connector aus der `AdapterRegistry` (unbegrenzt). Neue Fähigkeit = „Connector registrieren". Beweis: `DiscourseForumAdapter` (Foren-Verbreitung ohne Spezialcode, reitet die egress-gated Social-Pipeline; Permalink = Verbreitungs-Nachweis).
- **Separate Noch-nicht-Kunden-Daten**: `ResearchMission` (resümierbarer `state`) + `ResearchMissionMessage` (Dialog) + `Lead` (source/stage/fitScore/dedupeKey/convertedCustomer) + `LeadActivity` (append-only Historie). Priority-Scoring (WSJF-lite) mit Lexoffice-Umsatz als Signal.

### i18n / Mehrsprachigkeit (Backend v1)
Generische Übersetzbarkeit von Datentypen + bevorzugte Sprache pro User:
- **Infrastruktur (additives Modell)**: `TranslatableInterface` + `TranslatableTrait` (eine `translations`-JSON-Spalte pro Entity, Shape `{feld:{locale:wert}}`; Basis-Spalten bleiben die Quellsprache → UniqueConstraints/SearchFilter/Bestandsdaten unberührt). Die API liefert **Rohwerte + `translations`-Map** (kein serverseitiges Overlay) → Editoren können die Quellsprache nicht korrumpieren; die Anzeige löst clientseitig via `localize(entity, feld)` gegen die aktive Sprache auf. Neue Entity übersetzbar = Interface + Spalte, null Serializer-Config. `LocaleResolver` bleibt für Profil-Validierung (`supported_locales`) + spätere Mail-Lokalisierung (aktive Sprache: `User.preferredLanguage → Workspace.locale → Default`, bewusst **kein** `Accept-Language`).
- **Übersetzbar (15 Entities)**: TaskStatus, ProjectStatus, Tracker, TypeOfWork, ProjectType, Industry, AgreementType, Tag, CustomFieldDefinition, CustomFieldOption, SavedReply, ProjectTemplate, TaskTemplate, PublicForm, Product (skalare Textfelder name/label/title/description/body/successMessage/value).
- **User-Sprache**: `User.preferredLanguage`, editierbar über `MeProfileController` (validiert gegen `app.supported_locales`, Snapshot liefert `supportedLanguages`) — gilt für worktide-web **und** worktide-portal.
- **Offen (Folgeschritte)**: verschachtelte/Array-Felder (CustomField `options[]`, TaskTemplate `defaultChecklist[]`) — Formular-Feld-/Options-/Zeilen-Labels sind seit B3 abgedeckt; Sprachwahl im Profil + `TranslationsFields`-Editor + Portal-DE/EN sind **gebaut**, offen bleiben der Web-SPA-String-Sweep und das **Content-i18n-Rendering** (`getTranslation()` in die Serialisierung, Phase 3) sowie Suchfilter in Nicht-Default-Sprache (Escape-Hatch: MySQL-8-Virtual-Column-Index). Fahrplan: [docs/i18n-plan.md](docs/i18n-plan.md).

### Seit dem letzten Stand neu gebaut (2026-07-05 → 2026-07-12)

Eine große Welle hat mehrere zuvor als „offen"/„geplant" geführte Blöcke realisiert — die betroffenen Phasen-Punkte unten sind entsprechend nachgezogen. Kompakt:

- **Kundenportal (`worktide-portal`) — live als eigene React-App** (React 19 + Vite + React-Router 7, JWT + httpOnly-Refresh-Cookie, i18n DE/EN, Per-Workspace-Branding via `brandingProvider`). Seiten: Dashboard, Tickets/Support, Dateien, Verträge/Angebote, Ideen (+ Voting/Brainstorm), Dokumente, Formulare, Proposals, Social-Freigaben, Benachrichtigungen, Einstellungen, Newsletter, Terminbuchung, Abwesenheit, Monitoring/Status, Login/Passwort-Setzen. Backend: 23 `Portal*`-Controller unter `/v1/portal/*` (`PortalAccessResolver`-Tenant-Isolation, `ROLE_PORTAL`). Invoices- + Goals-**UI** inzwischen gebaut: Goals als Abschnitt der „Ziele & Ideen"-Seite (`IdeasPage`), Rechnungen als eigener, feature-gegateter Screen (`InvoicesPage`, `/rechnungen`, Sidebar-Eintrag).
- **Terminvereinbarung / Booking (Calendly-Klon) — erledigt** (war Phase E): `MeetingType` + `Booking` + `StaffCalendarConnection` (ICS-Import) + `CalendarBusyBlock`; public `GET/POST /v1/book/{slug}` (+ Slots, Honeypot, Rate-Limit), public Reschedule + Cancel per Token, ICS-Free/Busy, Abwesenheits-Abzug aus Slots, In-Portal-Buchung. `BookingSlotService` + `BookingMailer` + `worktide:booking:sync-calendars`-Cron.
- **Newsletter — erledigt** (neu): `Newsletter` (hierarchischer Themenbaum: slug/icon/color/position, `isSubscribable`/`isMandatory`/`isArchived`, `estimatedFrequency`, übersetzbar) + `NewsletterSubscription` (Double-Opt-In: `consentedAt`/`consentSource`/`confirmedAt`/`revokedAt`) + `NewsletterIssue` (Draft→Sent, Fan-out an descendant-Abos) + `NewsletterTemplate` (wiederverwendbar). `NewsletterConfirmController` + `NewsletterConfirmSigner` (signierter Confirm-/Unsubscribe-Link), `NewsletterMailer`, Portal-Verwaltung `/v1/portal/newsletters`.
- **Notifications + Zustellkanäle — erledigt** (deckt Phase G „Notification-Preferences"): `Notification`-Entity (dedupliziert per `sourceEventId`, read/delivered-State), In-App-Bell (Mercure-live), **Email** (`NotificationEmailNotifier`, empfänger-lokalisiert) + **Chat** (Slack/Mattermost/Teams via `UserChatWebhook`), gebündelte/entprellte Batch-Zustellung (`app:notifications:flush-batch`) + **Digest** (`app:notifications:send-digest` daily/weekly), Preferences pro User/Contact (Frequenz/Kanal/Typ/Quiet-Hours). Go-Live-Checkliste in [docs/notifications-go-live.md](docs/notifications-go-live.md).
- **CRM-Ausbau**: `CustomerAgreement` + `CustomerAgreementRevision` (immutable Historie) + `AgreementLineItem` (editierbare, übersetzbare Vertragspositionen, „Piece E1"); `Invoice` + `InvoiceStatus` + **Lexoffice-Sync** (`lexoffice:sync-contacts`/`-invoices`/`-revenue`); `ProjectOffer` + `ProjectProposal` (Angebot/Quote-Kette); `Idea`/`IdeaVote` + `BrainstormNote` (Portal-Engagement); `CustomerGoal`; `ContactAbsence`; `SystemIncident` + `SystemUptimeDay` (Portal-Monitoring/Statusseite).
- **Workspace-Mitglieder-Verwaltung**: Avatar-Endpoints, Member-Deaktivierung + Task-Handover beim Entfernen, Admin-Profil-Edit (`WorkspaceMember*`-Controller).
- **Cross-Workspace-Projekt-Sharing** (#65): `ProjectShare` + `ProjectShareInvitation` (Accept-Flow, Rollen, Scoping via `WorkspaceScopeExtension`).
- **Dashboard-Read-Models** (`/v1/dashboard/{my-tasks,open-customer-tasks,recent-status-updates,wall,project-blocked}`) — schlanke, gescopte Endpunkte für die SPA-Widgets + „The Wall".
- **Kunden-Dateien/Ordner**: `Folder` (polymorpher Baum, `isHiddenForConnectUsers`) + Portal-Datei-Bereich (`/v1/portal/files`, Upload/Download), Tags + KI-Tag-Vorschläge.
- **Sicherheits-Härtung** (#48–57): SSRF-Gates auf Channel-Adaptern + Outbound-Webhooks, Public-Form-Hardening (DoS/Info-Leak/TOCTOU), Capability-Gating für Import/Batch, Voter-Fixes, Cross-Workspace-Abweisung; Portal-Auth **M1** (Refresh-Token als httpOnly-Cookie statt JSON-Body, `RefreshToken`-Session-Metadaten).

---

## Phase A — Frontend-Polish

**Ziel:** Was bereits gebaut ist, vollständig sichtbar und benutzbar machen. Kein neues Backend-Konzept.

- ~~Dashboard-Widgets ersetzen die Platzhalter:~~ — **erledigt** (konfigurierbares `react-grid-layout`-Dashboard, `WIDGET_REGISTRY`, Layout persistiert via `/v1/me/preferences`)
  - ~~**ActiveTimer-Widget** (große Stoppuhr + Heute-Summe + Quick-Start)~~ — **erledigt** (`ActiveTimerWidget` + `FloatingTimer`, `/v1/timers/*`)
  - ~~**"Alle offenen Kunden-Aufgaben"** (cross-project Liste)~~ — **erledigt** (`OpenCustomerTasksWidget`)
  - ~~**"Meine Aufgaben"** mit Tabs Heute / Diese Woche / Überfällig~~ — **erledigt** (`MyTasksWidget`)
- **Eingeschränkte Mitarbeiter-Verfügbarkeit im Dashboard (workspace-übergreifend)** — reduzierte Verfügbarkeit einer Person (Abwesenheit / reduzierte `UserCapacity` / Krankmeldung via `AbsenceIntakeAssistant`) soll im Dashboard **jedes** Workspaces angezeigt werden, in dem die Person Mitglied ist — nicht nur im Heimat-Workspace. Zielbild: ein Dashboard-Widget / Team-Verfügbarkeits-Panel, das je Workspace die aktuell bzw. demnächst eingeschränkt verfügbaren Mitglieder listet (wer, Zeitraum, Umfang/Grund), gespeist aus `Absence` + `UserCapacity`. Cross-Workspace-Aggregation über die Workspace-Mitgliedschaften der User; Tenant-Isolation (Phase T) beachten — nur Verfügbarkeits-/Abwesenheits-Metadaten über die Grenze teilen, keine workspace-fremden Inhalte. — offen
- Sidebar-Polish:
  - ~~Pinned / Recent Projects unter "Meine Projekte"~~ — **erledigt** (Sidebar-Favoriten)
  - ~~Sammelprojekte vs Kunden-Projekte Gruppierung~~ — **erledigt** (`MyProjectsSidebar`: Favoriten / „Eigene" (ohne Customer-FK) / pro-Kunde-Gruppen)
- ~~**Quick-Add Cmd+K Popover** — globaler Shortcut, Task in Sekunden anlegen~~ — **erledigt** (QuickAddDialog: Cmd+K, Task + Projekt)
- ~~**Calendar-View** — FullCalendar-React, Tasks mit dueOn als Events~~ — **erledigt** (`/calendar`)
- ~~**Globale Suche** — cross-resource Suche (Tasks, Projects, Customers, Contacts, Documents)~~ — **erledigt** (GlobalSearchDialog, Cmd+/)
- ~~**Smart-Links** — externe URLs als Rich-Cards (oEmbed: YouTube, Figma, Confluence, …)~~ — **erledigt**: serverseitiger `GET /v1/links/preview?url=`-Proxy (`LinkPreviewResolver`: oEmbed-Provider-Whitelist → OpenGraph-Fallback) hinter dem `EgressGuard` (Modul `link_preview`, eigener Cache-Pool + Rate-Limiter 60/min/User, SSRF-gehärtet, 204 bei geblockt/unauflösbar); SPA rendert echte Titel/Thumbnails/Favicon via `externalLinkCard.tsx` (DocumentEditor-Paste-Handler routet Worktide-Refs → LinkCard, andere URLs → externe Card, Fallback auf Host-Chip). Live-verifiziert (YouTube-oEmbed, example.com-OpenGraph, unauth 401, SSRF 169.254/localhost → 204)
- ~~**Status-Updates** — strukturierte Projekt-Berichte (was läuft, Risiken, nächste Schritte)~~ — **erledigt** (Backend): `ProjectStatusUpdate`-Entity (CRUD unter `/v1/project_status_updates`), `ProjectHealth`-RAG (on_track/at_risk/off_track/on_hold/complete), drei Sektionen summary/risks/nextSteps, Autor via `createdByUser`, pro-Projekt-Feed (`?project=`), Domain-Events + Webhooks via `DomainEventEmitterSubscriber`. Report-Editor-UI **erledigt** (SPA): „Status-Updates"-Tab auf der Projekt-Detailseite + Dashboard-Widget „Status-Updates"
- **Hauptmenü aufräumen** — die Sidebar/Top-Nav ist über die Phasen hinweg gewachsen; selten gebrauchte Einträge (v. a. Einstellungs-/Admin-Punkte wie Workflow-Editor, KI-Kosten, Kanäle, Notification-Preferences, Board-Config etc.) sollen aus dem Hauptmenü heraus. Zielbild: eine zentrale **Einstellungs-Seite** (gruppierte Sektionen) für globale/administrative Optionen **plus** kontextnahe Erreichbarkeit direkt auf der jeweils zugehörigen Seite (z. B. Board-Config im Board, Sprint-Settings im Sprint-Board). Das Hauptmenü behält nur die häufig genutzten Arbeits-Views. — offen
- ~~**Top-Level-Routes** ausbauen: Kalender, Planer, Personen, Auswertungen~~ — **erledigt** (alle vier als Routen vorhanden)
- ~~**404-Seite** — eigene, gebrandete Not-Found-Seite für die Staff-SPA (und ggf. Portal) statt der Default-Fehlerseite, mit „zurück zum Dashboard"-Link.~~ — **erledigt** (beide SPAs): `NotFoundPage` in `worktide-web` (Catch-all `*` innerhalb der authentifizierten Shell, Sidebar/Header bleiben, „Zurück zum Dashboard"-Link) und in `worktide-portal` (gebrandete Standalone-Seite mit `BrandMark`/`Footer` + „Zur Übersicht", ersetzt die bisherige stille `*→/tickets`-Weiterleitung; explizites `/→/tickets` bleibt), DE/EN.

---

## Phase B — Issue-Tracking-Architektur

**Ziel:** Worktide vom Task-Manager zum Issue-Tracker upgraden — Jira-Pendant.

### Schicht 1 — Datenmodell
- ~~**Trackers** (Bug / Feature / Story / Support) als eigene Entity, Task bekommt FK zu Tracker. M:N zu CustomFields.~~ — **erledigt** (Backend, B-Sweep)
- ~~**Versions / Releases** mit `sharing`-Enum (none/descendants/hierarchy/tree/system), `effectiveDate`, Status open/locked/closed, optional Wiki-Page pro Version. Tasks bekommen `fixedVersion`.~~ — **erledigt** (ProjectVersion)
- ~~**IssueRelation-Typen** erweitern: aktuell 1 Typ, ausbauen auf `blocks`, `duplicates`, `relates`, `follows`, `precedes` + `delay`-Spalte für Scheduling.~~ — **erledigt** (TaskDependency-Typen)

### Schicht 2 — Workflow-Engine
- ~~**Workflow-per-Tracker × Status × Role**: WorkflowTransition + WorkflowPermission. Wer darf welchen Status-Wechsel auslösen, welche Felder sind in welchem Status editierbar.~~ — **erledigt** (Backend; SPA prüft Transitions im Board client-seitig vor)
- ~~**Visueller Workflow-Editor** (Drag-Drop, ähnlich Asana Workflow Builder).~~ — **erledigt** (SPA): Übergangs-**Matrix** unter `/workflow` (Admin-Nav) — Zeilen = von-Status, Spalten = zu-Status, Zelle togglet eine `WorkflowTransition` je Tracker (Baseline = null-Tracker); Zell-Popover setzt erlaubte Rollen + Label, Default-open-Semantik sichtbar („offen"-Badge). CRUD gegen `/v1/workflow_transitions` (PATCH via merge-patch). Bewusst Matrix statt Node-Graph (kein neuer Graph-Lib-Dependency; bildet das `(Tracker × from → to)`-Modell 1:1 ab).

### Schicht 3 — Reporting
- ~~**Reports SPA-UI mit Charts** (Recharts)~~ — **erledigt** (Phase B.3b/B.3c): Tabs unter `/auswertungen` für Zeit, Burndown, Created-vs-Resolved, Cycle-Time, MRR und **Cumulative Flow** (Status-Bänder pro Tag via DomainEventLog-Replay). Workload als Overlay im Team-Planner.
- ~~**Velocity** (abgeschlossene Arbeit pro Sprint)~~ — **erledigt** (Phase B.4.2): `GET /v1/reports/velocity` + Velocity-Chart auf `/sprints`, Größe via `estimatedMinutes`. Story-Points als optionales Maß später möglich.
- **Konfigurierbare Custom-Dashboards** (Drag-Drop, pro Workspace persistiert) — **Backend erledigt**: `Dashboard`-Entity (workspace-scoped, CRUD unter `/v1/dashboards`, benannt, `widgets`-JSON im react-grid-layout-Shape, `position`-Ordering, Icon/Color). Sichtbar für alle Workspace-Member (via `WorkspaceScopeExtension`); Ersteller/Workspace-Admin dürfen bearbeiten/löschen (`DashboardVoter`). Abgegrenzt vom per-User-Layout in `UserPreferences.dashboardLayout`. Drag-Drop-UI verbleibt SPA.

### Schicht 4 — Erweiterte Views
- ~~**Workload-View** (Visualisierung pro User: gebuchte Stunden vs UserCapacity vs Absences)~~ — **erledigt**: als WorkloadOverlay im Team-Planner (`/v1/reports/workload`)
- ~~**Sprints / Backlog**: startDate / endDate / Sprint-State, Velocity, Burndown~~ — **erledigt** (Phase B.4.2): `Sprint`-Entity (projekt-scoped) + `Task.sprint`, `/sprints`-Board mit Backlog + Sprint-Spalten (DnD), Sprint-Burndown (`?sprint=`) + Velocity-Chart
- ~~**Public Forms**: öffentliche `/forms/<slug>` Endpunkte, generieren Tasks aus Submissions mit Custom-Fields-Mapping~~ — **erledigt** (Backend): `PublicForm` + `PublicFormSubmission`-Entities (Admin-CRUD unter `/v1/public_forms`), öffentliche `GET/POST /v1/forms/{slug}` (PUBLIC_ACCESS), Submission → Task im Ziel-Projekt mit nativem (title/description/priority) + `cf:<key>`-Custom-Field-Mapping, Honeypot + Per-IP-Rate-Limit, Audit-Record. **Form-Builder-UI + Public-Renderer erledigt** (SPA `/formulare` + Portal `/forms`, inkl. Feld-Logik/Berechnungen/Multi-Page); **globales Formular-Modell (B0)** nachgezogen (nullable Projekt + Customer-Empfänger, `PortalFormDraft` für resümierbare Einreichungen), Feld-/Options-/Zeilen-Label-Übersetzung (B3) durch den Normalizer

---

## Phase C — Helpdesk + Mail-Integration

**Ziel:** Inbound-Mail wird zu Tasks/Conversations. Outbound-Mail aus Worktide. Brücke zur KI-Schicht.

### Schicht 1 — Mailbox-Layer (FreeScout-inspiriert)
- **Mailbox-Entity** workspace-scoped: Name, IMAP/SMTP/OAuth-Config, Signature, Auto-Reply, isShared — **realisiert als generische `Channel`-Entity** (source-agnostisch: email/chat/webhook-Adapter aus der `AdapterRegistry`, `authConfig` encrypted-at-rest, SSRF-gehärtet). Microsoft-Graph-OAuth angebunden (s. u.); Google-Workspace-OAuth noch offen.
- **Auth-Verfahren pro Mailbox** wählbar:
  - **SMTP + IMAP mit Passwort** (Generic, App-Passwords für 2FA-Provider)
  - **OAuth Microsoft 365 / Exchange Online** via Microsoft Graph — sowohl delegierte (User-Account) als auch Application-Permissions (Service-Mailbox). Scopes: Mail.Read, Mail.Send, Mail.ReadWrite. Refresh-Worker erneuert Tokens vor Ablauf.
  - **OAuth Google Workspace** via Gmail API
- Tokens encrypted-at-rest (libsodium via Symfony Secrets)
- **Mailbox-Sync-Worker** via Symfony Messenger (IMAP-IDLE / Graph-Webhooks / Polling als Fallback)
  - **Polling — erledigt** (`worktide:channel:pull`, alle 2 min, pro Channel).
  - **Microsoft-Graph-Push — erledigt**: `EmailGraphAdapter::consumeWebhook()` (clientState-verifiziert, delegiert an den Delta-Pull), `GraphSubscriptionManager` (subscribe/renew/unsubscribe, State verschlüsselt in `authConfig`), Validation-Handshake im `WebhookIngestController`, Reconcile-Cron `worktide:mailbox:graph-subscriptions:sync` (6-stündlich) + Eager-Subscribe nach OAuth-Connect. Polling bleibt Backstop (dedup ⇒ No-op).
  - **IMAP-IDLE — offen (bewusst zurückgestellt)**: echtes IDLE ist ein blockierender Dauer-Daemon (webklex `Folder::idle()` läuft endlos), passt nicht ins Cron-Modell → eigener Long-Running-Worker (ein Prozess pro Postfach). Das 2-Min-Polling deckt IMAP heute ab; IDLE nur bei konkretem Latenzbedarf.
  - **Gmail-Push (Pub/Sub) — offen**: benötigt GCP-Projekt + Topic; Gmail pollt weiter.
- ~~**Mehrfach-Email** pro Contact~~ — **erledigt (Contact)**: `ContactEmail` (address/primary/verified/label) + `ContactPhone` (number/**category** business·private·mobile·fax/primary) + `SocialProfile` (platform/url/handle, an Contact **oder** Customer) als eigene, workspace-gescopte API-Resources; die Alt-Spalten `Contact.email/phone/mobile` bleiben als Primär-Spiegel (bidirektionaler `ContactPrimaryInfoSyncListener`, ~70 Altleser unberührt), `ContactResolver` matcht über alle Adressen. Zusätzlich `Customer.invoiceEmail` (separate Rechnungsadresse). Inbox-Detail: `POST /v1/conversations/{id}/link-contact` (Absender → bestehenden Kontakt / neuer Kontakt + Kunde). **Offen**: Mehrfach-Email pro **User**.

### Schicht 2 — Threading
- ~~**Conversation-Entity**~~ — **erledigt** (subject, threadKey, customer, assignee, status Open/Pending/Closed/Spam, channel).
- **Thread-Entity** mit `type: customer | message | note | forward` — **bewusst NICHT als eine Entity gebaut.** customer = bestehender `InboundEvent`, message/forward = bestehende `OutboundMessage` (neu: `kind` Reply/Forward), note = neue `ConversationNote`. Vereinheitlicht als Read-Merge: `GET /v1/conversations/{id}/timeline` (`ConversationTimeline`-Service) liefert alle drei Quellen chronologisch mit Typ. Vermeidet den Rewrite der tragenden Ingest/Outbound-Entities.
- ~~**Internal Notes** als Thread-Type `note` — privat, mit @-Mentions~~ — **erledigt**: `ConversationNote`-Entity (CRUD unter `/v1/conversation_notes`, `isPinned`), `@/v1/users/<uuid>`-Mentions feuern `conversation.user_mentioned` (`ConversationNoteMentionNotifier`, geteilter `MentionExtractor` mit Document-Mentions).
- ~~**Forwarding** als Thread-Type `forward`~~ — **erledigt** via `OutboundMessage.kind = Forward`.
- ~~**Saved Replies** workspace-scoped, mit Variablen-Interpolation~~ — **erledigt**: `SavedReply`-Entity (CRUD unter `/v1/saved_replies`, `shortcut`) + `POST /v1/saved_replies/{id}/render` (`SavedReplyRenderer`: `{{customer.*}}`/`{{conversation.subject}}`/`{{agent.*}}`, unbekannte Platzhalter bleiben stehen).

### Schicht 3 — Collaboration
- **Collision Detection** via Mercure-Presence — Hinweis wenn 2 User dieselbe Conversation öffnen
- ~~**Auto-Reply pro Mailbox** (Empfangsbestätigung)~~ — **erledigt**: Auto-Reply-Felder auf `Channel` (persönliches Postfach = Owner-Nachricht, geteiltes = Team-Nachricht; HTML + Plain + Betreff + Pro-Absender-Throttle), gesetzt über den bewusst großzügigeren `PUT /v1/channels/{id}/auto-reply` (persönlich → Owner/Admin, geteilt → jedes aktive Mitglied). `AutoReplyResponder` (im `InboundEventProcessor`, LIVE-only, feuert für persönliche **und** geteilte Postfächer) mit dreifachem Loop-Schutz (MailRelevanceClassifier gegen bulk/auto/no-reply · Pro-Absender-Throttle · `Auto-Submitted`/`Precedence`/`X-Auto-Response-Suppress` im Versand). Neuer fokussierter Sende-Pfad `SendOutboundMessage` → `OutboundMessageSender` (egress-gated `email_outbound`, per-Nachricht statt poll-all — die bestehenden Human-in-the-Loop-Entwürfe bleiben unversendet), HTML-Body (`OutboundMessage.bodyHtml`) in allen drei Email-Adaptern (IMAP/Graph/Gmail). SPA: Auto-Antwort-Sektion im Channel-Dialog (DE/EN). Live gegen Mailpit verifiziert (multipart/alternative + Loop-Header).
- **Phone-Conversation** (manuelles Ticket für Telefonate)

### Schicht 4 — Routing + Conversion
- ~~Auto-Resolve: Eingehende Mail → Contact via from-email → Customer + Projekt-Kontext~~ — **erledigt**: `ContactResolver` (im `InboundEventProcessor`-Seam) matcht die from-Email auf einen `Contact` im Workspace und setzt `event.senderContact` + `conversation.customer`. Lookup-only (kein Auto-Anlegen unbekannter Sender). `ContactRepository::findOneByWorkspaceAndEmail`.
- ~~1-Klick "Aus Konversation Task anlegen"~~ — **erledigt**: `POST /v1/conversations/{id}/create-task` `{project, title?}` → `ConversationTaskConverter` (Titel=Subject, Description=erste Inbound-Nachricht, Status=Workspace-Default, `createdVia=Email`, `Task.sourceConversation` als Herkunfts-Link).
- **Inbound-Webhook für Mail-Provider mit Webhook-API** (SendGrid, Mailgun, Resend) — offen.

### Schicht 5 — Externe Ticket-System-Sync (Jira / Redmine)
- ~~Bidirektionale Entity-Sync-Foundation: `EntitySync` + `SyncableAdapter`, `EntityChangeOutbox` + Worker, `RedmineAdapter` + `JiraAdapter` (live verifiziert), Webhook-Push ohne Polling~~ — **erledigt** (Phase C.7.1–C.7.7)
- **Import-Filter pro Verbindung**: Beim Einbinden eines externen Ticket-Systems konfigurierbare Filter, die **nur Tickets importieren, die einer Person im Workspace zugeordnet sind** — direkt als Assignee **oder** als Mitleser/Watcher (Jira `watcher`, Redmine `watcher_id` / `assigned_to_id`). Verhindert das Einsaugen ganzer fremder Projekte. Filter greift sowohl beim initialen Backfill als auch bei eingehenden Webhook-Events.
  - **Fundament — erledigt**: `ExternalIdentity`-Entity (External-User→Worktide-User-Mapping pro Channel, CRUD unter `/v1/external_identities`) + `InboundImportFilter`-Service (`ExternalParticipant`-DTO; Relevanz = Assignee/Watcher löst über explizites Mapping *oder* Email-Match auf einen Workspace-Member auf). Side-effect-frei, von Backfill und Webhook gemeinsam nutzbar.
  - **Discovered-Import (C.7.6) — erledigt**: `EntityApplier` parkt ungemappte, relevante Snapshots als `DiscoveredExternalRecord` (`DiscoveredRecordCollector` + `InboundImportFilter`-Gate, idempotent pro `(channel, externalId)`). Read-only-API `/v1/discovered_external_records` + Aktionen `import` (neuer Task + `EntitySync`-Mapping), `link` (an bestehenden Task), `dismiss` (`DiscoveredRecordImporter`, re-entry-safe, Pending-Guard → 409). `EntitySnapshot.participants` von Redmine (`assigned_to.id`) + Jira (`assignee.accountId`/`emailAddress`) befüllt.
  - **Watcher-Listen — erledigt**: Redmine via `include=watchers` (List + Einzel-Issue), Jira via separatem `/issue/{key}/watchers`-Call (nur bei `watchCount>0`, best-effort). Assignee + Watcher als `ExternalParticipant`, dedupliziert. Jira-Teilnehmer bringen `emailAddress` mit → Email-Match greift; Redmine ist id-only (Email-Auflösung dort = Folgeschritt).
  - ~~**SPA-UI für das Discovered-Postfach**~~ — **erledigt**: „Entdeckt-Postfach" in der Staff-SPA (`/discovered`, Admin-Nav) listet die ungemappten Records mit Status-Filter, Channel-Name, Teilnehmern; pro Record Import (neuer Task via `ProjectCombobox`), Verknüpfen (bestehender Task via neuem `TaskCombobox`) und Verwerfen gegen die `/import`|`/link`|`/dismiss`-Endpunkte (409 → Toast).
  - **Offen (Folgeschritte)**: Email-Auflösung für Redmine-User (Payload liefert keine Email); Pull-Runner (heute kommt Discovery nur über Entity-Webhooks).

---

## Phase D — KI-Integration / Digitaler Projektmanager

**Ziel:** Worktide wird vom Verwalter zum aktiven Vorschlager.

> **Status: Fundament realisiert** (siehe „Bereits gebaut → KI-Agenten"). Der Human-in-the-Loop-Seam
> (`AIRecommendation` + `RecommendationApplier` + Accept/Reject), `LlmProviderInterface`, der `ai_agents`-Transport,
> das `EgressGuard`-Outbound-Gate und mehrere Agenten (Triage, Marketing, Upgrade-Outreach, Research/Akquise,
> Distribution-Planner) laufen produktiv — inkl. eines **generischen Agent-Action-Layers** (Connector-Katalog →
> LLM-Plan → ein generischer Applier-Zweig). Offen: Ollama/EU-Routing-Policy, breiter InboundEvent-Pipeline-Ausbau,
> Prompt-Caching, autonomer Schritt-für-Schritt-`AgentRun`-Trace.

### Schicht 1 — Infrastruktur
- **`AIRecommendation`-Entity**: suggestion, reasoning (Markdown), appliesTo polymorph (Task/Project), status (pending/accepted/rejected), source
- **`LlmProviderInterface`** + Anthropic-Claude-Implementierung (default, `src/Service/Llm/AnthropicLlmProvider.php` als Keim vorhanden) + Ollama-Adapter für datenschutzsensible Workspaces
- Prompt-Caching für wiederkehrende Workspace- + User-Kontexte

#### Modell-Routing-Policy — lokal vs. Cloud pro Task-Typ
**Prinzip:** kein globaler „lokal oder Cloud"-Schalter, sondern eine **Routing-Policy pro Task-Typ** hinter dem `LlmProviderInterface`. Der Per-Workspace-Toggle wählt nur die *Default-Zuordnung* + harte Grenzen (Datenschutz-Workspace → lokal erzwungen). Lokale Modelle übernehmen die volumen-starken, reasoning-armen Aufgaben; Claude bleibt für Agentik + Qualität.

| Task | Default |
|---|---|
| Klassifikation, Extraktion, Tagging, Zusammenfassung, Embeddings (→ Suche Phase D⁺) | **lokal** (Ollama/vLLM) |
| Aufwands-Schätzung, einfache Reply-Suggestions | lokal, Fallback Claude bei Unsicherheit |
| Agentische Web-Recherche, Tool-Ketten, Outbound-Drafting | **Claude** (Tool-Calling-Zuverlässigkeit + Multi-Hop-Synthese) |
| Datenschutz-Workspace (Kundendaten dürfen die Infra nicht verlassen) | **lokal erzwungen** (deckt Data-Residency, Phase F) |

- **Serving lokal**: **vLLM** für Produktion (Batching/Throughput bei Concurrency), **Ollama** für Dev + kleine Workspaces. Concurrency ist der Engpass „ständig scannender" Agenten — eine GPU-Box serialisiert; für periodische Cron-Batch-Scans unkritisch, für hohe Parallelität eigene Serving-Instanz.
- **Wichtig — lokal ≠ kein Egress**: ein lokales Modell macht nur das *Reasoning* lokal. Web-Recherche-Calls (Such-API/Fetch) verlassen das System trotzdem und laufen weiter durch den EgressGuard.
- **Strategischer Kern**: der Hauptgewinn von lokal ist **Data Residency** (Kundendaten verlassen die Infrastruktur nie), nicht primär Kosten — genau das Verkaufsargument für datenschutzsensible Workspaces.

#### Agent-Runtime — dauerhaft laufende Prozesse (Ticket-/Mail-Scan, Web-Recherche)
**Prinzip:** keine bespoke `while(true)`-Daemons. Der „ständig laufende" Charakter entsteht aus der crash-sicheren Triade **Trigger → Queue → Worker**, die bereits steht (`ChannelPullCommand` ist die Blaupause: Cron-Tick → dispatcht `ProcessInboundEventMessage` → Worker verarbeitet async, inkrementell über Cursor). Ein Scan-Agent = *stateless Worker + durabler Cursor + Scheduler-Tick*, kein Endlosprozess.

- **Eigener Messenger-Transport `ai_agents`** getrennt vom schnellen `async` (Webhooks/Mercure/Mail). Lange, teure, rate-limitierte LLM- + Recherche-Jobs dürfen die schnelle Zustellung nicht aushungern. Eigener Worker mit **niedriger Concurrency**, aggressiverem Backoff (`max_retries: 5`, `multiplier: 3`, `max_delay`).
- **Lange Läufe zerlegen** (Step-Function-Muster): `Fetch → Analyze → Act` als je eigene, sich neu einreihende Messages — resumierbar, beobachtbar, kein minutenlanger Worker-Lock. Long-Timeout-Pool nur für echte Single-Long-Calls.
- **EgressGuard als Gate** (`src/Egress/`, bereits vorhanden): Agenten lesen/recherchieren + schlagen frei vor, aber jeder Outbound (Web-Recherche, „Agent antwortet auf Ticket") läuft durch `EGRESS_ALLOW`. Human-in-the-Loop by default.
- **Output = `AIRecommendation` + `DomainEventLog`, nicht autonome Aktion.** Ergebnis per **Mercure** live in die SPA pushen (Kanal existiert).
- **Symfony Scheduler** (`RecurringMessage`) statt/neben OS-Cron für die wachsende Zahl periodischer Jobs (`channel:pull`, `evaluate:autopilots`, `lexoffice:sync`, `social:publish-due`, `run-schedules`): Zeitplan im Code, testbar, überlebt DDEV-Rebuilds.
- **Rate-Limiting + Budget**: `symfony/rate-limiter` (Storage → Redis, siehe Phase S) vor jedem LLM-/Recherche-Call, plus Token-/Kosten-Accounting pro Workspace. Transiente Fehler (429/5xx) → Backoff-Retry; permanente → Dead-Letter (`failed`).
  - ~~**Token-/Kosten-Accounting**~~ — **Fundament erledigt**: `LlmUsageLog` (ein Datensatz pro LLM-Call: Input/Output-Tokens, `costMicros` via `LlmPricing`, Provider/Modell, Feature + Workspace) wird in beiden Providern (`AnthropicLlmProvider`/`InfomaniakLlmProvider`) geschrieben — jeder Pfad durch `LlmProviderInterface` ist erfasst; `AiUsageContext` liefert die Feature-/Workspace-Attribution (gesetzt von den Assistants). **Admin-KI-Kosten-Dashboard erledigt**: `GET /v1/ai-usage/summary` (gescopt, Owner/Admin) aggregiert `LlmUsageLog` → Totals + nach Feature/Modell/Tag; SPA `/ki-kosten` (KPI-Kacheln, Kosten-pro-Tag-Chart, Feature-/Modell-Breakdowns, 7/30/90-Tage). ~~**Kontingent-/Budget-Enforcement**~~ — **erledigt**: `LlmBudgetGuard` prüft in beiden Providern vor dem Call das per-Workspace-Monatsbudget (`settings.ai.monthlyBudgetMicros`, 0=unbegrenzt) gegen die Monats-Ist-Summe; über Budget → `LlmBudgetExceededException`, kein Spend. `PUT /v1/ai-usage/budget` (Owner/Admin) setzt es; das Dashboard zeigt Verbrauch/Budget/Rest + Fortschrittsbalken. Kostentreiber: der Auto-„Ticket?"-Call pro eingehender Mail (`InboundEventProcessor`) — **gedrosselt** via per-Workspace-Rate-Limit (`ai_auto_suggest`, 30/h; darüber kein Auto-Suggest, On-Demand bleibt). Offen: diesen Massen-Call zusätzlich auf ein kleines/lokales Modell routen (→ Per-Task-Routing).
- **Skalierung → Phase S**: Doctrine-Transport (heute) für Dev ausreichend; unter Last auf **Redis/Valkey-Transport** wechseln (`MESSENGER_TRANSPORT_DSN` env-swappbar). Worker in **eigenen Containern** unabhängig vom Web skaliert, in Prod via supervisord mit `--time-limit`/`--memory-limit` + Auto-Restart (PHP-Leak-Schutz).
- **Entscheidung Polling vs. Langläufer**: Cron-Tick (1–5 min Latenz, einfach, crash-sicher) ist Default. Echte Sekunden-Latenz (IMAP IDLE / persistenter Agent-Loop = echter Daemon) nur bei konkretem Bedarf.

### Schicht 2 — Aufwands-Schätzung
- ~~AI schlägt `estimatedMinutes` vor — basierend auf TimeEntry-History ähnlicher Tasks~~ — **erledigt**: `EffortEstimationAssistant` rankt abgeschlossene Workspace-Tasks nach Ähnlichkeit (gleicher Tracker / geteilte Tags), nimmt nur solche mit echter TimeEntry-Ist-Zeit als Ground-Truth, validiert die LLM-Zahl auf einen sinnvollen Int. Reused Human-in-the-Loop-Envelope: `POST /v1/tasks/{id}/ai-estimate` → `ai_agents` → Pending `AIRecommendation` (neuer Kind `estimate`) → Accept setzt `Task.estimatedMinutes` via `RecommendationApplier`. SPA: `AiEstimatePanel` im Task-Sheet (neben KI-Triage).
- **Lern-Schleife** (offen): bei Task-Close Schätzung vs. Ist vergleichen, das per-Workspace-Modell kalibrieren.

### Schicht 3 — Auto-Scheduling
- ~~Aus (Prio + Schätzung + Deadline + UserCapacity + Absences) → Vorschlag wann~~ — **Phase 1 erledigt**: LLM-Planer (`SchedulePlanningAssistant`) ordnet die offenen, zugewiesenen Tickets eines Staff (≤40) und verteilt sie über die freie Kapazität der nächsten 14 Tage (`UserCapacity` − Absences); `PlanScheduleHandler` schreibt sequentielle `startOn`/`scheduledEnd`-Slots. Trigger `POST /v1/me/ai-plan` (ai_agents, Budget/Usage-getrackt, Feature `schedule`). Dashboard-Widget „Meine Planung" (`/v1/dashboard/my-schedule`). Neues **Disziplin-Feld** (User + Task) als Grundlage.
- ~~**Phase 2:** rollenbasierte Angebote unzugeordneter Tickets, Accept → Re-Plan~~ — **erledigt**: `GET /v1/dashboard/task-offers` bietet offene, **unzugeordnete** Tickets (kein User-Assignee) mit `requiredDiscipline == User.discipline` an; `POST /v1/tasks/{id}/claim` weist zu (409 bei bereits vergeben/abgeschlossen) + re-plant, `POST /v1/tasks/{id}/decline-offer` merkt die Ablehnung (`TaskOfferDismissal`). SPA-Widget „Angebotene Tickets" (Übernehmen/Ablehnen, Hinweis zum Setzen der Disziplin).
- ~~**Phase 3:** Staff meldet Krankheit/spontane Abwesenheit per Freitext an die KI → Re-Plan + Kunden-Info-Angebot~~ — **erledigt**: `AbsenceIntakeAssistant` (Feature `absence_intake`) parst den Freitext (mit **einer Rückfrage** bei Unklarheit); `POST /v1/me/absence-intake` legt die `Absence` an, **re-plant** (Planer nullt die Abwesenheitstage) und liefert die im Fenster terminierten, kundenseitigen Tickets gruppiert nach Kunde; `POST /v1/me/absence-notify` erzeugt pro Kunde einen **egress-gated `OutboundMessage`-Entwurf** (Template). SPA: Freitext-Feld + Rückfrage + „Kunden informieren" im „Meine Planung"-Widget. Human-in-the-Loop durchgängig.
- **Plan ändern** — erledigt: Quick-Actions je Ticket im „Meine Planung"-Widget (einen Tag später / aus Plan entfernen, via Task-PATCH auf `startOn`/`scheduledEnd`) + volles Drag-Editing im Team-Planner (dieselben Felder). Offen bleibt nur ein optionaler Vorschlags-/Akzeptier-Modus (heute wird der Plan direkt angewendet). Kunden-Notify-Entwürfe end-to-end verifiziert (egress-gated `OutboundMessage`, `no_recipient`-Pfad sauber).

### Schicht 4 — Mail + Outbound
- ~~AI klassifiziert Conversations~~ — **erledigt** (Fundament): Conversation-Triage (`TicketTriageAssistant::triageConversation` → Summary + Status inkl. Spam, + „Ticket aus Konversation?") über das `AiTriagePanel` auf der Inbox-Detailseite. Feinere Kategorien/Prioritäts-Scoring bleiben ausbaubar.
- ~~Reply-Suggestions im Conversation-Editor — nutzt Saved Replies als Few-Shot-Beispiele~~ — **erledigt**: `ReplySuggestionAssistant` + synchroner `POST /v1/conversations/{id}/suggest-reply` (Saved Replies als Few-Shot, kunden­sprachiger Entwurf), SPA-Button „KI-Antwort" im Reply-Composer fügt den Entwurf zum Bearbeiten ein — nichts wird automatisch gesendet.
- **Automatische Status-Updates an Kunden bei Conversation-Closed** (offen).

### Schicht 5 — Smart Features
- ~~**KI-Kommandozeile** aufs Dashboard~~ — **erledigt (MVP)**: Freitext-Eingabe (`AiAssistantWidget`) → `AgentCommandRouter` (LLM, Feature `command`) klassifiziert in Intent (Abwesenheit / Ticket anlegen / Produkt bewerben / Rückfrage) + extrahiert Namen; `POST /v1/me/agent-command` schlägt vor (Namen serverseitig deterministisch aufgelöst), `/execute` handelt nach Bestätigung. **Berechtigungslogik:** der Assistent handelt strikt AS der User — jede Aktion ist an dessen Capability gebunden (`PermissionResolver`: `TaskCreate`, Workspace-`EDIT` für Marketing; Abwesenheit self-service), keine Eskalation; fehlende Rechte → `denied`/403. Erweiterbar um weitere Intents.
- "Diese Aufgabe in Subtasks aufbrechen" (AI-Breakdown)
- Natural-Language-Search → API-Filter-Generierung

---

## Phase D⁺ — Such-Service (optional)

**Ziel:** Skalierbare Volltextsuche sobald die MySQL-`LIKE`-Variante an ihre Grenzen stößt. Vor Phase C (Mail-Bodies) selten gerechtfertigt; danach typischerweise mit dem ersten 100k+-Workspace fällig.

> **Status: realisiert.** `SearchProviderInterface` mit `MysqlSearchProvider` (Default) + `MeilisearchProvider` (Drop-in via `SEARCH_PROVIDER`), globale `/v1/search` + Cmd+/ in der SPA, Auto-Indexing (Doctrine-Listener → `ai`-… `SyncSearchIndexMessage`) + `worktide:search:reindex`. Indexiert u. a. conversation/task/customer/contact/project/document/comment **sowie `lead`/`research_mission`**. Der Research-Agent nutzt den Index zusätzlich als interne „aus-der-eigenen-DB"-Quelle + für typo-tolerante Lead-Dedup. Offen: per-Workspace-Toggle, Typesense-Adapter, Hybrid-/Vektor-Suche, MySQL-Query-Tokenisierung (verbose Queries).

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

## Phase S — Skalierung & Performance (Infrastruktur)

**Ziel:** Worktide auf viel Daten + read-heavy Traffic vorbereiten. Querschnitts-Phase — die drei Blöcke sind einzeln zündbar, nicht als Sequenz zu verstehen. Bewusst von den fachlichen Features entkoppelt.

### Storage — S3 / Object-Store
- ~~**S3-Adapter aktivieren**~~ — **erledigt & verifiziert**. `StorageAdapterFactory` (`src/Service/Storage/StorageAdapterFactory.php`) baut zur Laufzeit aus `FILE_STORAGE_ADAPTER` (`local`|`s3`) entweder `LocalFilesystemAdapter` oder `AwsS3V3Adapter` (mit `S3_ENDPOINT`/`S3_USE_PATH_STYLE`/`S3_PREFIX` für MinIO/UpCloud/R2, `directory_visibility: private`); `league/flysystem-aws-s3-v3` ist requirt + installiert. Dev läuft via `.env.local` bereits gegen MinIO (write/read/delete getestet). Callers hängen nur an `FilesystemOperator`.
- **Vor lokalem `var/uploads`-Überlauf** ziehen — sobald nennenswerte Datenmengen (File-Attachments, Document-Vault, Mail-Anhänge aus Phase C) anfallen. S3-kompatibel: AWS, UpCloud, MinIO (self-hostable), Cloudflare R2.
- SSE-Verschlüsselung + Retention-Policies (GoBD) bleiben Teil des **Document-Vault (Phase E)** — der bloße Bucket-Anschluss ist davon entkoppelt und kann früher passieren.

### HTTP-/Response-Cache
- **API-Platform HTTP-Caching**: Cache-Tags mit Entity-basierter Invalidierung, `Vary`-Header, Reverse-Proxy vorgeschaltet — **Souin** (Caddy-Modul, self-hostable, MIT — kein Lock-in) oder Varnish. Größter Durchsatz-Hebel bei read-heavy API-Traffic.
- **Achtung Multi-Tenancy + JWT**: Responses nur cachebar mit `Vary` auf Auth + Workspace-Scope, sonst strikt `private`. Tenant-Isolation im Cache-Key ist Voraussetzung, nicht Nice-to-have.

### Application-Cache
- **Redis / Valkey als Cache-Backend**: Doctrine Result- + Metadata/Query-Cache, Symfony `cache.app`-Pool, Rate-Limiter- + Lock-Storage (heute noch Default-Adapter). **Valkey** (BSD, Redis-Fork) statt Redis, um dem Lizenz-Lock-in auszuweichen.
- Der **Mercure-Hub** skaliert bereits separat (externer Dienst) — kein Teil dieses Blocks.

---

## Phase T — Data-Leak-Prevention & Tenant-Isolation-Guardrails (Querschnitt)

**Ziel:** Mandanten-/Kundendaten dürfen nie über Workspace- oder Customer-Grenzen sickern und nie ungewollt das System verlassen. Die Sicherheitswelle (#48–57) hat Einzelfälle gefixt — dieser Block macht die dahinterstehenden **Regeln explizit** und **fest verdrahtet in Tests**, sodass ein Regelverstoß den Build bricht statt erst im Audit aufzufallen. Querschnitt — läuft **früh und dauerhaft** mit, nicht erst bei Enterprise-Bedarf (Voraussetzung fürs Kundenportal, das Nicht-Mitarbeitern eine reduzierte Sicht öffnet).

### Richtlinien (verbindlich für jede neue Entity / jeden Endpunkt)
- **Mandanten-Scoping by default**: jede API-Platform-Resource ist workspace-gescoped (`WorkspaceScopeExtension` + Voter). Eine Resource ohne Scope ist ein Fehler, keine Option — Ausnahmen (`PUBLIC_ACCESS`: Forms, Booking, Newsletter-Confirm) sind explizit annotiert + dokumentiert.
- **Portal-Isolation über einen Seam**: jeder `/v1/portal/*`-Endpunkt löst den Tenant ausschließlich über `PortalAccessResolver` auf (Contact → Customer), nie über rohe IDs aus dem Request-Body/-Pfad. Contact von Kunde X sieht nie Daten von Kunde Y.
- **Keine Cross-Workspace-Referenzen**: FKs (assignee, project, customer, folder, …) müssen im selben Workspace liegen; Denormalizer/Voter weisen fremde IDs ab (kein „ID-Erraten").
- **Egress ist default-deny**: jeder Outbound (Mail, LLM, Web-Recherche, Chat-/Forum-/Webhook-Push) läuft durch den `EgressGuard` + `EGRESS_ALLOW`-Kategorie. Kein direkter HTTP-Call an vom User/Admin gelieferte Hosts ohne **SSRF-Guard** (interne IPs, `file://`, Redirect-Rebinding geblockt).
- **Serialisierung leakt nichts**: Responses whitelisten Felder über Serialization-Groups; Secrets (`authConfig`, Tokens, Passwörter) sind write-only / `is_shown_once` und encrypted-at-rest — nie in Response, Fehlermeldung, `@id`-IRI oder Log.
- **Public/unauth. Endpunkte** sind rate-limitiert + honeypot-geschützt und **info-leak-frei** (keine User-/Record-Enumeration, konstante Antwortzeiten/-inhalte).
- **Fremdinhalt ist Daten, nie Instruktion (Prompt-Injection)**: Alles, was ein KI-Agent aus untrusted Quellen liest (Email-Bodies, Ticket-/Kommentar-Text, Formular-Eingaben, Dateinamen/-inhalte, externe Ticket-Sync-Payloads), wird strikt vom System-/Tool-Kontext getrennt eingebettet. Ein Agent darf aus solchem Inhalt **keine** Tool-Calls, Egress-Aktionen, Scope-Wechsel oder Preisgabe anderer Datensätze ableiten — die Isolations- und Egress-Regeln oben gelten für Agent-Aktionen genauso wie für Endpunkte.

### In Tests gießen (fail-closed, im CI-Gate — `.github/workflows/tests.yml`, MySQL 8 + PHP 8.4, läuft bei jedem Push/PR)
- **Scope-Coverage-Test**: iteriert reflexiv über alle `#[ApiResource]`-Klassen und assertet, dass jede in der Scope-Whitelist steht **oder** explizit als public markiert ist → eine neue Entity ohne Scoping bricht den Build automatisch.
- **Cross-Tenant-Functional-Tests**: User aus Workspace A bekommt `404/403` auf Collection-Filter, Item-Get **und** Patch-mit-fremder-FK aus Workspace B.
- **Portal-Isolation-Tests**: pro `/v1/portal/*`-Endpunkt ein Test, dass Kunde X keine Daten von Kunde Y sieht (inkl. Datei-Download + Newsletter/Booking-Token).
- **Egress-default-deny-Test**: ohne `EGRESS_ALLOW` schlägt jeder Outbound fehl; SSRF-Payload-Suite (169.254.x, `localhost`, `file://`, DNS-Rebinding, offene Redirects) wird geblockt.
- **Serialization-Leak-Test**: Assert, dass sensible Felder (`password`, `authConfig`, `*token*`, Fremd-Workspace-Refs) in keiner API-Response auftauchen.
- **Regression-Verankerung**: jeder der #48–57-Fixes bekommt einen dedizierten Test, damit die Lücke nicht zurückkehrt.
- ~~**Prompt-Injection-Suite**~~ — **gebaut** (`tests/Service/PromptInjection/`): jeder Agent, der Fremdinhalt verarbeitet, wird mit einer Payload-Batterie (`PromptInjectionPayloads`) gefüttert — „ignore previous instructions", eingebettete Fake-System-Prompts, „maile alle Kunden / lösche Projekt / rufe Tool X", versteckter Text (HTML-Kommentare, Zero-Width), Cross-Tenant-Exfiltration. Ansatz (deterministisch, CI-fähig, kein echter LLM): eine **aufzeichnende Fake-LLM** (`RecordingLlmProvider`) belegt zwei Invarianten — (1) **Prompt-Hygiene**: der Fremdinhalt landet nur in der User-Message, nie im System-Prompt; (2) **Output-Guardrail unter Kaperung**: selbst wenn der (simulierte) LLM der Injektion folgt, neutralisiert die Validierungsschicht das Ergebnis — `PortalTicketSuggester` weist einen fremden/mandantenübergreifenden `projectId` ab, `TicketTriageAssistant` (Ticket **und** Konversation/Email) verwirft erfundene Tracker/Priorität/Status, `TagSuggestionAssistant` wendet erfundene Tags nie an (nur `suggestedNewTags`), `AgentActionPlanner` verwirft nicht-katalogisierte Kanäle (Exfiltration) und recipient-lose Outbound-Messages. Offen: Mail-Klassifikation/Reply-Suggestions sobald gebaut; optionale behaviorale Tests gegen einen echten LLM.

---

## Phase E — CRM + Customer-Portal

**Ziel:** CRM-3 + CRM-4 abschließen, Kunden bekommen ihre eigene Sicht.

- ~~**CRM-4 Invoice**~~ — **erledigt (Fundament)**: `Invoice`-Entity + `InvoiceStatus`-FSM (draft/sent/paid/cancelled), Portal-Sicht `/v1/portal/invoices`. Rechnungen kommen heute über die **Lexoffice-Sync** herein (s. u.); der lokale Billing-Run-Cron, der aus ServiceSubscription (`nextBillingOn ≤ heute`) materialisiert, bleibt optionaler Folgeschritt.
- ~~**Lexoffice-Integration**~~ — **erledigt**: `lexoffice:sync-contacts` / `sync-invoices` / `sync-revenue` (Umsatz fließt als Signal ins WSJF-Priority-Scoring). Auto-Push bei `invoice.created` als Folgeschritt.
- **Erinnerung an nicht abgerechnete Stunden** — Reminder/Notification, wenn abrechenbare (billable) `TimeEntry`-Einträge offen sind und noch nicht in eine Rechnung/Abrechnung überführt wurden (periodischer Check pro Kunde/Projekt, z. B. als Ergänzung zum Billing-Run-Cron).
- **Document-Vault**: Rechtssicherer File-Store — SSE-Verschlüsselung, Versionierung, Retention-Policies (GoBD), PDF-Volltextsuche, Audit-Log. Baut auf dem S3-Adapter aus **Phase S** auf (der reine Bucket-Anschluss ist dorthin herausgezogen)
- **Verbindungs-/Zugangs-Bookmarks pro Kunde** — Verwaltung von **Browser-, SSH- und SFTP-Lesezeichen** je Kunde, wo möglich einem `CustomerSystem` zugeordnet (Staging-/Admin-URL, SSH-Host, SFTP-Deploy-Ziel eines Systems) statt verstreut in lokalen Configs. Zentrale, workspace-/kunden-gescopte Ablage; Zugangsdaten encrypted-at-rest (write-only, nie in Response/Log), Tenant-Isolation + Egress-Regeln aus **Phase T** gelten. **Später — konsumierende Clients**: **KDE-Dolphin**-Integration (Netzwerk-/Places-Lesezeichen für `sftp://`/`fish://`-Ziele) und **Chrome-/Firefox-Extension** (Browser-Bookmarks). Die Clients ziehen die Bookmarks über die API (via PAT/OAuth), damit ein Mitarbeiter Kunden-Systeme direkt aus Dateimanager bzw. Browser erreicht.
- **Kundenportal pro Workspace (beliebige Domain)** — **größtenteils gebaut** (`worktide-portal`, eigene React-App; siehe „Seit dem letzten Stand neu gebaut"). CRM-Kontakte werden freigeschaltet und erhalten eine **strikt reduzierte Sicht** (kein abgespeckter Workspace-Zugang). Offen aus der Ideensammlung: Retainer-Burndown, Self-Service-Upsells, SEO-Audit-Fragebogen, Signatur (Invoices-/Goals-UI inzwischen gebaut — Rechnungen als eigener Screen, Ziele in der Ideen-Seite). Vollständige Ideensammlung (Dashboard, Tickets/Support, Monitoring/Statusseiten, Angebote/Verträge/Signatur, Abrechnung + Retainer-Burndown, Self-Service-Upsells, Pitch-Modus für Projekt-Ideen + Feature-Voting, SEO-Audit-Fragebogen, KI-Funktionen) in **[docs/customer-portal-ideas.md](docs/customer-portal-ideas.md)**.
  - **Früh festlegen (Querschnitt)**: Rechte pro Contact über die bestehende `Capability × Role`-Matrix · Login extern via **Magic-Link oder SSO** (kein weiteres Passwort) · **Modularität** — jeder Baustein pro Workspace an-/abschaltbar (workspaceweites Override-Prinzip) · Portal bleibt reduzierte Sicht, kein Workspace-Zugang.
  - ~~**Auslieferung**: eigene Portal-App pro Workspace unter eigener Domain~~ — **erledigt** (`worktide-portal` als eigene React-App gegen `/v1/portal/*`, Custom-Domain-Mapping möglich). Login via httpOnly-Refresh-Cookie steht; **Magic-Link / externes SSO** noch offen. Der ursprünglich geplante TYPO3-Extbase-Plugin (`wapplersystems/worktide-customer-portal`) bleibt eine mögliche zusätzliche Portal-Variante gegen dieselbe API.
  - **„Portal als Kunde ansehen" (Staff-Preview / Impersonation)** — Mitarbeiter rufen die Portal-Sicht eines Kunden auf, ohne dessen Passwort zu kennen (generierter Magic-Link bzw. signiertes Impersonation-Token), um zu sehen, was der Kunde sieht.
- ~~**Terminvereinbarung** (Calendly-Klon)~~ — **erledigt**: `MeetingType` + `Booking` + `StaffCalendarConnection` (ICS-Import) + `CalendarBusyBlock`, public `/v1/book/{slug}` (+ Slots/Reschedule/Cancel), ICS-Free/Busy, Abwesenheits-Abzug, In-Portal-Buchung. **Zwei-Wege**-Sync mit Google/Outlook (heute nur ICS-Read-Import) bleibt Folgeschritt.
- **Themability**: Light/Dark, Per-Workspace-Branding (Primary-Color + Logo), Custom-Theme-Builder — **teilweise**: das Portal hat einen `brandingProvider` (Per-Workspace-Farbe/Logo); Custom-Theme-Builder + Staff-SPA-Branding offen.

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
- ~~**Notification-Preferences**: sofort / verzögert / digest / DnD-Fenster, pro Channel~~ — **erledigt für Email / Mercure / Chat** (In-App-Bell + Email + Slack/Mattermost/Teams; Frequenz/Typ/Quiet-Hours pro User **und** Contact). Offen: Mobile- / Browser-Push.
- **AI Studio + AI Teammates**: persistente AI-Agenten als Task-Assignees
- **Multiple Sandboxes** (Test-Environment parallel zum Produktiv-Workspace)
- **Repository-Integration**: Git/GitLab/GitHub Branch + PR-Sicht im Task, Smart-Commit-Syntax

---

## Kritische Entscheidungspunkte

- **Vor Phase B**: Will Worktide explizit gegen Jira konkurrieren oder reicht Tracker-Light? Im Light-Fall: Trackers + Versions reichen, Workflow-per-Tracker streichen.
- **Vor Phase D**: AI als User-facing-Vorschlag oder Hidden-Boost (Schätzungen automatisch übernehmen, unsichtbar)?
- **Vor Phase T**: **nicht** auf Enterprise-Bedarf warten (Gegenteil von Phase F) — die Data-Leak-Guardrails samt fail-closed-Tests müssen stehen, **bevor** der erste echte Kunde ins Portal gelassen wird, da das Portal die Mandantengrenze nach außen öffnet.
- **Vor Phase F**: Erste Enterprise-Kunden-Anfrage abwarten, vorher keine SSO/2FA/Sandboxes.
- **Vor Phase G**: Marketplace und OAuth-Server erst wenn 50+ aktive Workspaces produktiv.

---

## Empfohlene Reihenfolge

Die ursprüngliche Sequenz A → C → B → D → D⁺ → E ist **weitgehend abgearbeitet**: A, B und D⁺ stehen, ebenso die Foundation von C, D und E; Kundenportal, Booking, Newsletter und Notifications sind live. Die Reihenfolge orientiert sich daher jetzt an den **Restarbeiten** — höchster Hebel zuerst:

1. **Gebautes produktiv schalten** — Go-Live-Config für Notifications/Mail (`EGRESS_ALLOW`, `MAILER_DSN`, `worker`/`scheduler`-Container; [docs/notifications-go-live.md](docs/notifications-go-live.md)). (Portal Invoices-/Goals-UI ✓ + Discovered-Postfach-UI ✓ + visueller Workflow-Editor ✓ + Smart-Links-oEmbed-Proxy ✓ erledigt.)
2. **Phase C — Helpdesk komplettieren**: Google-Workspace-OAuth, Collision-Detection (Mercure-Presence), Inbound-Webhook (SendGrid/Mailgun/Resend). (Auto-Reply pro Mailbox ✓ erledigt.) Schließt den Support-Loop, den Portal-Tickets bereits anstoßen.
3. **Phase D — KI-Ausbau** (Phase-C-Daten + Portal liefern jetzt den Kontext): Aufwands-Schätzung ✓ (Lern-Schleife offen), Mail-Klassifikation ✓ + Reply-Suggestions ✓ (Auto-Status-Update bei Close offen), danach Auto-Scheduling. Modell-Routing (Ollama/vLLM) für datenschutzsensible Workspaces parallel.
4. **Phase E — Rest**: Document-Vault (SSE + Retention/GoBD, baut auf dem S3-Adapter auf) + Portal-Vertiefung (Signatur, Retainer-Burndown, Magic-Link/SSO, Themability-Builder).
5. **Phase D⁺ — Rest**: per-Workspace-Toggle + Hybrid-/Vektor-Suche — erst wenn Volumen/Qualität es rechtfertigen.
6. **Phase F — Enterprise**: bedarfsgetrieben nach erster Enterprise-Anfrage (SSO/SCIM, 2FA/WebAuthn, Account-Lockout, Permission-/Notification-Schemes, Audit-SIEM-Export, OAuth-Server).
7. **Phase G — Plattform**: bei Bedarf.

**Phase S** (Skalierung) läuft **quer** zu dieser Sequenz, nicht als Schritt:
- **S3-Adapter — erledigt** (`FILE_STORAGE_ADAPTER`, Dev gegen MinIO verifiziert).
- **HTTP-Cache + Redis/Valkey** rücken nach oben: Portal + Notifications erhöhen Read-Traffic **und** Queue-/Rate-Limiter-Last (`ai_agents`- + `async`-Transport laufen heute noch auf Doctrine). Zünden, sobald Prod-Last es rechtfertigt — parallel zu jeder Phase möglich, da rein infrastrukturell.

**Phase T** (Data-Leak-Prevention & Tenant-Isolation-Guardrails) läuft **ebenfalls quer** und sollte parallel zu Schritt 1 anlaufen — das Kundenportal öffnet Nicht-Mitarbeitern eine Sicht, also müssen die Isolations-Regeln vorher in fail-closed-Tests verdrahtet sein.

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
