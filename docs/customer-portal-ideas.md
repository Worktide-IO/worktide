# Worktide Kundenportal – Ideensammlung

> Roadmap-Ideensammlung (Stand 2026-07-04). Gehört zu **Phase E — CRM + Customer-Portal**
> in [../ROADMAP.md](../ROADMAP.md) und weitet den dortigen (ursprünglich TYPO3-fokussierten)
> Portal-Punkt zu einem **Kundenportal pro Workspace auf beliebiger Domain** aus.

Kundenportal pro Workspace. CRM-Kontakte werden freigeschaltet und erhalten eine strikt reduzierte Sicht (kein abgespeckter Workspace-Zugang). Diese Sammlung ist thematisch geordnet und bündelt die bisher besprochenen Ideen.

---

## 1. Dashboard & Einstieg

- Zentrales Kunden-Dashboard mit Überblick
- Geführtes Onboarding (Checkliste, erste Schritte)
- Fortschrittsanzeige zu laufenden Projekten
- Blocker-Ansicht

## 2. Tickets & Support

- Tickets einsehen und neue erstellen
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
- Automatischer Wochen-/Monats-Digest per E-Mail
- Terminbuchung/Meeting-Slots
- Broadcast-Ankündigungen (Wartung, Features, Preise)

## 8. Wissen & Assets

- Freigegebener Wiki-/Knowledge-Base-Bereich (Self-Service)
- Geteilter Dateibereich für Deliverables/Assets
- Marken-Assets (Logos, Fonts, Guidelines)
- Sicherer, verschlüsselter Credentials-Ablageort

## 9. Selbstverwaltung

- Eigene Stammdaten, Ansprechpartner, Rechnungsadressen pflegen
- Eigene Portal-Nutzer verwalten (Kollegen einladen, Rechte im Rahmen)

## 10. Formulare & Audits

- **SEO-Audit-Fragebogen** – mehrteiliger Self-Service-Fragebogen (Ziele & KPIs, Zielgruppe, Keywords/Wettbewerb, technische Zugänge, Inhalte & Probleme), Speicherstand über mehrere Sitzungen, Antworten fließen in ein Audit-Ticket/-Projekt
- Generischer Formular-/Fragebogen-Baustein als Basis – SEO ist nur die erste Vorlage (Performance, Accessibility, Security folgen)

## 11. KI-Funktionen (Schwerpunkt)

- KI-Assistent im Portal (über MCP): Statusfragen, Ticket aus Freitext
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
