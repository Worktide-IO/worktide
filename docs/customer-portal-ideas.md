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

## 7. Kommunikation & Termine

- Echtzeit-Aktivitätsfeed & Benachrichtigungen (Mercure)
- **Einstellbare Benachrichtigungskanäle** – Kunde wählt pro Ereignistyp, wie er informiert wird: E-Mail, Chat (Slack / Mattermost („Kchat") / Teams) oder nur In-App. Pro Kanal ein-/ausschaltbar je Ereignis (neues Ticket-Update, Angebot/Vertrag, Monitoring-Incident, Datei-Freigabe, Digest). Optional Frequenz (sofort / gebündelt / täglich) und Ruhezeiten. Umsetzung über die vorhandenen HMAC-signierten Webhooks bzw. Kanal-Connectoren; Einstellungen pro Contact.
- Automatischer Wochen-/Monats-Digest per E-Mail
- Terminbuchung/Meeting-Slots
- Broadcast-Ankündigungen (Wartung, Features, Preise)
- **Newsletter-Verwaltung (Baum-Struktur)** – hierarchisch organisierte Newsletter/Themen, die der Kunde im Portal einzeln abonnieren bzw. abbestellen kann.
  - **Datenmodell:** Newsletter als Baum (Selbstreferenz `parent` + `position`); pro Knoten **Titel** und **Beschreibung**; beliebige Verschachtelungstiefe.
  - **Verwaltung (Admin-Web):** CRUD + Baum-Editor (anlegen, umbenennen, verschieben, verschachteln) im Admin-SPA.
  - **Freischaltung pro Kunde:** im Kunden-Datensatz einzeln freischaltbar (analog zur Portal-Freischaltung); nur freigeschaltete Newsletter erscheinen im Portal des jeweiligen Kunden.
  - **Portal (Kunde):** freigeschaltete Newsletter im Baum an-/abwählen (Opt-in/Opt-out je Knoten); Abo-Status pro Contact.

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
