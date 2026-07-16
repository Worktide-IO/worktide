# Worktide Kundenportal – Ideensammlung

> Roadmap-Ideensammlung (Stand 2026-07-06). Gehört zu **Phase E — CRM + Customer-Portal**
> in [../ROADMAP.md](../ROADMAP.md) und weitet den dortigen (ursprünglich TYPO3-fokussierten)
> Portal-Punkt zu einem **Kundenportal pro Workspace auf beliebiger Domain** aus.
>
> **Umsetzungsstand:** Phase 1 ist live — eigene `^/v1/portal`-Endpoints (ROLE_PORTAL, kuratierte
> DTOs), `PortalAccessResolver`, Support-Loop (Tickets lesen/erstellen/antworten) und Kunden-Dashboard;
> Frontend `Worktide-IO/worktide-portal` (Vite+React). Anonymisierte Screenshots unter
> `worktide-portal/docs/screenshots/`. Die folgende Sammlung ist die thematisch geordnete
> Gesamt-Vision; die meisten Bausteine sind noch offen.

Kundenportal pro Workspace. CRM-Kontakte werden freigeschaltet und erhalten eine strikt reduzierte Sicht (kein abgespeckter Workspace-Zugang). Diese Sammlung ist thematisch geordnet und bündelt die bisher besprochenen Ideen.

---

## Umsetzungsstand §7 & §8 (Analyse 2026-07-10)

Ergebnis einer Codebase-Analyse (Backend `worktide`, SPAs `worktide-web` / `worktide-portal`)
vor Inangriffnahme von §7. Viel Fundament existiert bereits — der Fokus liegt auf *Lücken
schließen* und *ans Portal ausliefern*, nicht auf Greenfield.

**§7 Kommunikation & Termine**

| Punkt | Stand | Lücke |
|---|---|---|
| Event-Log + Inbox + 6 Resolver (`DomainEventLog`→`Notification`, Mercure-Push) | ✅ geliefert | — |
| Glocke + Inbox-Seite (beide SPAs) | ✅ geliefert | — |
| **Portal Echtzeit (Mercure)** | ✅ **erledigt 2026-07-10** — Glocke + Inbox live via `worktide-portal/src/lib/mercure.ts` (scoped Token-Endpoint `/v1/portal/mercure-token`, Vite-Proxy für CORS in dev) | — |
| Inbox-Nacharbeit: `task.assignees_changed` | ✅ **erledigt 2026-07-10** — Event aus `TaskActionsController::setAssignees`, `TaskAssignedResolver` behandelt beide Events | — |
| Inbox-Nacharbeit: katalogweite Launches | ⚠️ offen | Audience für globale `product.created` definieren (`LaunchResolver.php:22-27`) |
| HMAC-Webhooks (in/out) | ✅ geliefert | — |
| **Benachrichtigungskanäle** (In-App + E-Mail pro User) | ✅ **erledigt 2026-07-10** — `notificationPreferences` auf `UserPreferences`; Portal `/v1/portal/notification-preferences` + Seite `/einstellungen`; Instant-E-Mail via `NotificationEmailNotifier` (Egress-Gate, Ruhezeiten, pro Typ) | Chat-Adapter (Slack/Mattermost/Teams) weiterhin offen |
| E-Mail-Digest (Täglich/Wöchentlich) | ✅ **erledigt 2026-07-10** — `app:notifications:send-digest` + Cron (`frankenphp/crontab` + `.ddev`) | — |
| **Newsletter-Baum** (Opt-in) | ✅ **erledigt 2026-07-10** — `Newsletter`-Baum (Self-Ref) + `NewsletterSubscription` + `Customer.enabledNewsletterIds`; Admin-Baum-Editor (`worktide-web` /newsletter) + Kunden-Reiter „Newsletter"; Portal `/newsletter` Opt-in (`PortalNewslettersController`) | Drag-Reorder verschoben (Move via Parent-Auswahl); i18n der Titel verschoben |
| Terminbuchung / Meeting-Slots (7D) | ✅ **erledigt 2026-07-10** — `MeetingType`+`Booking`, Slot-Engine, öffentliche `/v1/book/{slug}`-Endpoints, Bestätigungs-Mail + ICS; öffentliche Buchungsseite `worktide-portal /book/:slug` (+ /book/cancel/:token); Admin `worktide-web` Terminarten (Verfügbarkeits-Editor) + Buchungen | Externer Kalender-Sync (Google/Outlook), Reschedule, In-Portal-Buchungslink für eingeloggte Kunden, Absence-Abzug verschoben |
| Broadcast-Ankündigungen | ❌ fehlt | verwandt mit Newsletter/Launch-Audience |

**§8 Wissen & Assets**

| Punkt | Stand | Lücke |
|---|---|---|
| `File`/`FileVersion`, polymorph, Flysystem local↔S3/MinIO, Upload+Versionierung+ETag | ✅ geliefert | — |
| Wiki/KB = `Document` + `DocumentSpace` + Revisions + Workflow | ✅ geliefert (Portal **read-only, flache Liste**) | Baum-/Space-Navigation im Portal |
| **Nativer Dateiaustausch (Portal-Modul)** | ⚠️ nur Ticket-Anhänge (`<input>`, Download-only) | Drag-&-Drop, Inline-Vorschau, Datei-im-Kontext; `FileTarget::Document` ist Stub |
| Presigned URLs | ❌ zurückgestellt (alles streamt durch die App) | S3-Presign up/down |
| Verschlüsselter Credentials-Tresor | ❌ heute Klartext (`CustomerSystem.credentialsNotes`) | Encryption-at-Rest + Entität |
| Virenscan (ClamAV) | ❌ fehlt | Scan-on-Upload |
| Marken-Asset-Bibliothek | ❌ fehlt (nur Instanz-Logo + `BRAND_*`-Env-Farben) | Entität + Library-UI |

**Struktur-Hebel:** neue Notification-Typen = Enum-Case + Resolver (keine Transport-Plumbing);
Portal-Feature freischalten = `PortalAccessResolver::FEATURE_KEYS` + `Workspace.settings.portal.features`
minus `Contact.portalHiddenFeatures`. `Contact` hat **kein** `locale`-Feld (i18n-Tier-1-Voraussetzung).

---

## 1. Dashboard & Einstieg

- Zentrales Kunden-Dashboard mit Überblick
- Geführtes Onboarding (Checkliste, erste Schritte)
- Fortschrittsanzeige zu laufenden Projekten
- Blocker-Ansicht

## 2. Tickets & Support

- Tickets einsehen und neue erstellen
- **Intelligenter Anliegen-Eingang** – Kunde beschreibt sein Anliegen frei per Textfeld; die KI prüft bestehende Tickets (und Wiki/FAQ) auf Übereinstimmung und schlägt vor: an passendes Ticket anhängen, neues Ticket anlegen (mit Titel/Priorität/Projekt-Vorschlag) oder auf vorhandene Antwort verweisen. Kunde bestätigt/wählt/korrigiert – nichts wird ohne Klick erstellt (Human-in-the-Loop). Rückfragen der KI nur bei Bedarf.
- Threaded-Kommentare + @Mentions ans Team
- SLA-/Reaktionszeit-Anzeige
- CSAT/Feedback nach Ticket-Abschluss

## 3. Monitoring & Systeme

- Monitoring-Meldungen zu den eigenen Websites
- Statusseite pro CustomerSystem (Uptime, Incidents, Historie)
- Angekündigte Wartungsfenster
- Incident-Postmortems zum Nachlesen
- Domain-/Hosting-/Lizenz-Übersicht mit Ablaufdaten

## 4. Angebote, Verträge & Abrechnung

- Angebote zusammenstellen
- Angebote digital freigeben/signieren (Statusverlauf)
- Verträge im Portal abschließen
- Vertragsübersicht mit Laufzeiten, Fristen, Verlängerungs-Warnung
- Vertragsversionierung/Änderungshistorie
- AVV/DPA und DSGVO-Dokumente zum Signieren
- Rechnungs-/Abrechnungshistorie + Vorschau nächste Fälligkeit
- Retainer-/Budget-Burndown (verbrauchte vs. verfügbare Stunden)
- Zeit-/Leistungsnachweise (gescoped, optional freigebbar)

## 5. Sales & Kommerziell

- Self-Service-Add-ons/Upsells (Buchung mit einem Klick)
- Upgrade/Downgrade von Service-Paketen
- Guthaben-/Prepaid-Stunden-Konto
- Referral-/Empfehlungsprogramm

## 6. Ziele & Ideen

- Ziele einsehen (ggf. mit Gamification/Fortschritt)
- Ideen fürs Business sehen
- Feature-Voting (Kunden upvoten Ideen)
- Brainstorming
- **Präsentation der Ideen zu Projekten** – dedizierter Pitch-Modus pro Projekt (Nutzen, Aufwand, Kostenschätzung, Vorher/Nachher-Visuals), Kundenaktion Annehmen → Angebot/Task / Rückfrage / Ablehnen, optional Varianten A/B im Vergleich

## 6a. Roadmap / Feature-Vorschau (Produkt-/Feature-Roadmap)

Eine schlanke Produkt-/Feature-Roadmap, die im Portal sichtbar gemacht werden kann. **Bewusst abgegrenzt** vom „Präsentation der Ideen zu Projekten" (Pitch-Modus pro Projekt, oben): das hier ist die **produkt-/feature-bezogene Roadmap**, nicht projektbezogen.

- **Neue Entität** (z. B. `RoadmapItem` / `FeaturePreview`) mit den üblichen Traits (`EntityIdTrait` UUIDv7, `TimestampableTrait`, `SoftDeletableTrait`).
- **Minimum:** `title`; `description` (Rich-Text/Markdown); `images` – Cover + optional Galerie über die vorhandene **`File`/`FileVersion` + `FileStorage`-Infrastruktur** (local/S3), **nicht** als lose URLs.
- **Früh mitdenken (spart spätere Migrationen):**
  - `kind` (Enum: `feature | product | service`)
  - `stage` (Enum: `geplant | in_arbeit | beta | live`) → macht daraus eine echte Roadmap mit Fortschritt
  - `targetQuarter`/ETA (optional, bewusst vage – „Q3 2026")
  - `isPublished` (Draft vs. im Portal sichtbar) + `position` (Sortierung)
  - optionaler FK auf `Product`/`ProductVersion`/`ServiceSubscription` – verknüpft die Vorschau mit dem echten Datensatz, sobald gelauncht
- **Scope (wichtigste frühe Entscheidung): `workspace` nullable**
  - `null` = **Worktide-Plattform-Roadmap** (eigene Worktide-Features, in *allen* Kundenportalen sichtbar)
  - gesetzt = Roadmap *einer Agentur/eines Workspaces* für deren eigene Kunden
- **Wiederverwendung des Vorhandenen:**
  - **Sichtbarkeit/Freischaltung:** über das bestehende Portal-Feature-Gating (Workspace-`features` + `Contact::portalHiddenFeatures`) ein `roadmap`/`vorschau`-Flag; optional Audience-Targeting (alle Kunden vs. bestimmte).
  - **Portal-Endpunkt:** read-only `/v1/portal/roadmap` (nur `isPublished` + für den Kunden sichtbar), sortiert nach `stage`/ETA; im Admin-Web CRUD zur Pflege.
  - **i18n:** `title`/`description` übersetzbar – passt zum i18n-Backlog (Tier 1, `Contact.locale`).
  - **Launch-Benachrichtigung:** beim Übergang `stage → live` kann der bereits gebaute **Launch-Notification-Trigger** feuern („Neues Feature/Produkt live").
  - **Feature-Voting (optional):** Kunden drücken Interesse/Upvote aus – reuse des `Idea`/`IdeaVote`-Musters (deckt sich mit dem „Feature-Voting"-Punkt oben in §6).

> **→ Inbound-Feedback (Bugs / Feature-Wünsche / UI-UX-Änderungen).** Die *eingehende* Hälfte
> (Nutzer melden an Worktide) ist separat als geteiltes, tenant-übergreifendes Feedback-Board
> geplant — siehe **`docs/feedback-board-plan.md`** (Entscheidungen 2026-07-16: ein globales
> anonymisiertes Board aus `Task`/`Tracker`/`Comment`, cloud-first, Threaded-Replies, pro-Workspace
> Client-Toggle). Diese §6a hier bleibt die *ausgehende* Produkt-Roadmap.

## 7. Kommunikation & Termine

- Echtzeit-Aktivitätsfeed & Benachrichtigungen (Mercure)
- **Benachrichtigungs-Inbox (ausgeliefert) – offene Punkte:** die persistierte Inbox (Glocke + Seite, Web + Portal, gespeist aus dem DomainEventLog) ist live. Nachzuziehen:
  - **Zuweisungs-Änderungen:** aktuell wird nur bei Aufgaben-*Erstellung* mit Assignee benachrichtigt (`task.created`); für spätere Zuweisungs-Änderungen fehlt ein Event → `task.assignees_changed` einführen und im `TaskAssignedResolver` behandeln.
  - **Katalogweite Launches:** nur kundenspezifische Launches (`customerproduct`/`servicesubscription`) sind verdrahtet; für globale `product.created`/`productversion.created` muss zuerst das Empfänger-Publikum definiert werden (sonst Broadcast an alle).
- **Einstellbare Benachrichtigungskanäle** – Kunde wählt pro Ereignistyp, wie er informiert wird: E-Mail, Chat (Slack / Mattermost („Kchat") / Teams) oder nur In-App. Pro Kanal ein-/ausschaltbar je Ereignis (neues Ticket-Update, Angebot/Vertrag, Monitoring-Incident, Datei-Freigabe, Digest). Optional Frequenz (sofort / gebündelt / täglich) und Ruhezeiten. Umsetzung über die vorhandenen HMAC-signierten Webhooks bzw. Kanal-Connectoren; Einstellungen pro Contact.
- Automatischer Wochen-/Monats-Digest per E-Mail
- Terminbuchung/Meeting-Slots (7D ✅ erledigt 2026-07-10 — siehe Umsetzungsstand oben)
  - **Staff-Kalenderansicht + externer Kalender-Sync für Frei/Belegt** — ✅ **ICS + Ansicht erledigt 2026-07-10; OAuth-Variante offen.**
    Zwei Teile, additiv zu 7D:
    1. ✅ **Admin-Buchungskalender (erledigt):** Buchungen als Events in der bestehenden
       `worktide-web /calendar`-Ansicht (FullCalendar), markengrün, klickbar → `/buchungen`.
    2. **Externer Kalender (Frei/Belegt-Blocker):** ✅ **ICS-Feed-Ansatz erledigt** —
       `StaffCalendarConnection` (ICS-URL pro User) + `CalendarBusyBlock`-Cache; `IcsCalendarImporter`
       (SSRF-/Egress-`calendar_sync`-geschützt, VEVENT-Parser: UTC/TZID/all-day/CANCELLED) +
       `app:booking:sync-calendars` Command + Cron (alle 10 Min); `BookingSlotService` zieht die
       Belegt-Blöcke des Hosts ab; Admin-Seite `/kalender-sync` zum Hinterlegen der ICS-URL.
       **Offen (spätere Ausbaustufe):**
       - (a) **ICS-Feed-Abo (einfachste, kein OAuth):** Mitarbeiter hinterlegt seine geheime
         ICS-URL (Google/Outlook/Apple bieten sowas); Cron holt + parst die VEVENTs periodisch in
         einen Belegt-Cache. Read-only, near-real-time via Poll. Ergänzt die schon gebaute
         ICS-Erzeugung.
       - (b) **Google/Outlook-API (OAuth + FreeBusy):** reicher, näher an Echtzeit, ggf. Zwei-Wege
         (Buchung landet im Kalender des Mitarbeiters); nutzt die vorhandene Channels-OAuth-Plumbing
         (Graph/Gmail). Größer.
       - (c) CalDAV: generisch, aber komplex.
       - Feld/Modell: `MeetingType.host` bzw. eine `StaffCalendarConnection` (User + Feed-URL/OAuth-
         Token + letzter Sync); Belegt-Zeiten als Cache-Tabelle, die die Slot-Engine subtrahiert.
- Broadcast-Ankündigungen (Wartung, Features, Preise)
- **Newsletter-Verwaltung (Baum-Struktur)** – hierarchisch organisierte Newsletter/Themen, die der Kunde im Portal einzeln abonnieren bzw. abbestellen kann.
  - **Datenmodell:** Newsletter als Baum (Selbstreferenz `parent` + `position`); pro Knoten **Titel** und **Beschreibung**; beliebige Verschachtelungstiefe.
  - **Verwaltung (Admin-Web):** CRUD + Baum-Editor (anlegen, umbenennen, verschieben, verschachteln) im Admin-SPA.
  - **Freischaltung pro Kunde:** im Kunden-Datensatz einzeln freischaltbar (analog zur Portal-Freischaltung); nur freigeschaltete Newsletter erscheinen im Portal des jeweiligen Kunden.
  - **Portal (Kunde):** freigeschaltete Newsletter im Baum an-/abwählen (Opt-in/Opt-out je Knoten); Abo-Status pro Contact.
  - **TODO (offen, 2026-07-10): Newsletter-Vorlagen (Templates) + editierbar.** Pro Newsletter-Knoten
    wiederverwendbare Inhalts-/Versand-Vorlagen anlegen und **im Admin bearbeiten** (Editor). Nutzt den
    bereits queryfähigen `NewsletterSubscription`-Join als Empfängerliste, sobald „Newsletter versenden"
    gebaut wird. Offene Punkte: Template-Datenmodell (eigene Entität `NewsletterTemplate` mit Betreff +
    Rich-Text-Body, FK auf `Newsletter`?), Editor im Admin-SPA (BlockNote wie bei Documents oder
    einfacher Markdown/HTML-Editor), Platzhalter/Variablen (Anrede, Firmenname), Vorschau, und
    Anbindung an den Versand (Mailer + Egress-Gate wie beim Notification-Digest, siehe
    [notifications-go-live.md](notifications-go-live.md)).

## 8. Wissen & Assets

- Freigegebener Wiki-/Knowledge-Base-Bereich (Self-Service)
- Geteilter Dateibereich für Deliverables/Assets
- **Nahtloser Dateiaustausch für Projekte (nativ)** – eigenes Datei-Modul im Portal-Design, kein externes Nextcloud sichtbar. Baut auf den vorhandenen polymorphen Files auf; Storage dahinter S3-kompatibel (z. B. selbstgehostetes MinIO, EU-hostbar). Ein System, eine Auth, ein Design – perfekt integriert. Trade-off: keine Nextcloud-Sync-Clients / kein gemeinsames Office-Editing (später via Hybrid nachrüstbar).
  - Drag-&-Drop-Upload, Inline-Vorschau (PDF/Bild), Datei-Icons im Portal-Stil
  - Dateien im Kontext (am Projekt/Ticket), nicht als abstrakter Ordnerbaum
  - Up-/Download über presigned URLs (Storage nie direkt im Browser)
  - Rechte pro Contact über `Capability × Role` (ansehen vs. hochladen), Scope strikt auf eigenen Projektordner
  - DSGVO: Ablauf-/Löschregeln, Freigabelinks mit Ablaufdatum, Virenscan beim Upload (z. B. ClamAV)
- Marken-Assets (Logos, Fonts, Guidelines)
- Sicherer, verschlüsselter Credentials-Ablageort
- **Sprachvarianten von Dokumenten (per-locale documents)** – ein Dokument je
  Sprache ablegen (z. B. Vertrags-PDF DE + EN); Portal liefert die Variante zur
  Sprache des Betrachters (Fallback = Basis). Gehört hierher (Datei-Management),
  nicht zur Text-i18n. Ausgelöst durch `docs/content-i18n-authoring-plan.md`
  Piece E (Vertragsinhalte pro Vertrag übersetzbar) – der Textteil (E1) läuft
  dort, die Dokument-Varianten hier in §8. Baut auf dem seit 2026-07-12
  vorhandenen Datei-/Ordner-System auf (`Folder`/`FolderService`,
  `PortalFilesController`, `File`) – kein Greenfield.
  - **STATUS 2026-07-12:** an den Datei-System-Owner (Sven) übergeben, da es das
    von ihm aktiv entwickelte `File`/`Folder`-Modell erweitert (Kollisionsrisiko).
    Feature-Brief: **`docs/per-locale-documents-plan.md`**. Modell-Empfehlung:
    `File.locale` + `File.variantOf` (Basis + Sprachvarianten, Fallback = Basis;
    analog zum `labelI18n`-Muster). Der Vertragsdokument-Teil (E2,
    `CustomerAgreementRevision.file`) wird danach mit dem i18n-Workstream verdrahtet.

## 9. Selbstverwaltung

- Eigene Stammdaten, Ansprechpartner, Rechnungsadressen pflegen
- Eigene Portal-Nutzer verwalten (Kollegen einladen, Rechte im Rahmen)

## 10. Formulare & Audits

- **SEO-Audit-Fragebogen** – mehrteiliger Self-Service-Fragebogen (Ziele & KPIs, Zielgruppe, Keywords/Wettbewerb, technische Zugänge, Inhalte & Probleme), Speicherstand über mehrere Sitzungen, Antworten fließen in ein Audit-Ticket/-Projekt
- Generischer Formular-/Fragebogen-Baustein als Basis – SEO ist nur die erste Vorlage (Performance, Accessibility, Security folgen)
- **Formular-Engine à la Tally** – der generische Baustein wird zu einer vollwertigen Form-Engine ausgebaut, mit der sich **komplexe Entscheidungen** abbilden und dem Kunden im Portal bereitstellen lassen. Referenz zum Nachbauen: [tally.so/help/api](https://tally.so/help/api) (REST, `api.tally.so`, Bearer-Token, 100 req/min, Webhooks statt Polling).
  - **Datenmodell:** Form → Pages → Blocks; reiche Feldtypen (Text, Auswahl/Dropdown, Rating, Datei-Upload, …); **bedingte Logik / Branching** (Sichtbarkeit + Sprünge nach Antworten) als Kern für „komplexe Entscheidungen"; **Calculations**/berechnete Felder; Hidden-/Prefill-Felder.
  - **Bausteine:** Builder (intern), Renderer (Portal) und Submission-API + Webhooks bei Abschluss. Baut auf dem vorhandenen Forms-Slice auf (Backend `^/v1/forms/*`, Portal `FormsPage`/`FormFillPage`).
  - **Portal-Nutzen:** geführte, mehrstufige Entscheidungs-/Konfigurations-Formulare (z.B. Leistungs-/Paketwahl, Audit-Fragebögen); Antworten fließen in Ticket/Projekt/Angebot.

## 11. KI-Funktionen (Schwerpunkt)

- KI-Assistent im Portal (über MCP): Statusfragen, Ticket aus Freitext
- Intelligenter Anliegen-Eingang: KI gleicht Freitext mit bestehenden Tickets/Wiki ab (Dedup), macht Vorschläge, Kunde bestätigt
- Fortschritts-Zusammenfassung in Kundensprache
- Freigabe-Workflow für KI-generierte Social-Media-Posts (Human-in-the-Loop)
- Freigaben für Deliverables/Designs/Texte
- KI-Vertragsassistent (Klauseln einfach erklärt)
- KI schlägt aus Monitoring + Tickets proaktiv Maßnahmen/Angebote vor
- Monatliche „Was ist passiert"-Zusammenfassung pro Kunde
- KI-Vorschläge für Projekt-Ideen (landen als Entwurf im Pitch, Mensch gibt frei)
- KI ergänzt SEO-Fragebogen um relevante Keywords & offene Rückfragen

---

## Querschnitt (früh festlegen)

- **Rechte-Granularität pro Contact** – nicht jeder sieht Rechnungen; Umsetzung über die `Capability × Role`-Matrix
- **Login für externe Kontakte** – Magic-Link oder SSO statt weiterem Passwort
- **Modularität** – alle Bausteine pro Workspace an-/abschaltbar (passt zum workspaceweiten Override-Prinzip)
- Portal bleibt eine reduzierte Sicht, kein Workspace-Zugang
- **Internationalisierung / i18n (mehrsprachige Kunden)** – zwei getrennte Baustellen: übersetzbare *Daten* (unten) **und** UI-Strings (heute in beiden SPAs hart deutsch). Aktuell keine i18n-Infrastruktur; nur `Workspace.locale` (`de`).
  - **Voraussetzung:** `locale` pro `Contact` (Portal-Login ist kontaktbezogen), Fallback `Customer` → `Workspace.locale`; steuert die ausgelieferte Übersetzung.
  - **Tier 1 (kundensichtbar, größter Hebel):** `PublicForm` + Felder (Titel/Labels/Options/Validierung); System-Mails/Templates (Einladung, Passwort-Reset, Benachrichtigungen); `Notification`-Titel/Body (heute hart deutsch → Templates mit Parametern statt gespeicherter Strings); **`Newsletter` von Anfang an übersetzbar** (Titel/Beschreibung).
  - **Tier 2 (Labels/Taxonomien im Portal):** `ProjectStatus`, `TaskStatus`, `ProjectType`, `TypeOfWork`, `Industry`, `AgreementType`, ggf. `Tag`, `CustomFieldDefinition`/`CustomFieldOption`.
  - **Tier 3 (Katalog/Angebot):** `Product`/`ProductVersion` (Name, Beschreibung, Release-Notes), `ServiceSubscription`/`CustomerProduct`, `SavedReply` (Mehrsprach-Varianten).
  - **Tier 4 (situativ):** `SystemIncident`, `ProjectStatusUpdate` – oft ad hoc getippt → eher manuell/Live-Übersetzung.
  - **Nicht übersetzbar (nutzergeneriert):** Task-Titel/-Beschreibung, `Comment`, `ConversationNote`, `TimeEntry`, `Document`, `ProjectProposal`-Freitext, Formular-Submissions – Inhalt in Autorensprache; höchstens optionale Anzeige-Maschinenübersetzung, nichts speichern.
  - **Mechanik:** generische `translations`-Tabelle `(entity, id, field, locale)` **oder** JSON-Spalte `{locale: wert}` je Feld; Serializer-Normalizer liefert das aufgelöste Locale. Nicht alles auf einmal – mit Tier 1 starten.
