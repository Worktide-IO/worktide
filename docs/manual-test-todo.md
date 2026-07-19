# Manuelle Test-/QA-Todo-Liste

Offene manuelle Verifikationen, die nicht durch die automatisierte Test-Suite abgedeckt sind
(z. B. echte prod-Smoke-Tests hinter dem Egress-Gate). Erledigtes durchstreichen (`~~…~~`)
oder abhaken (`[x]`).

## Smart-Links oEmbed-Proxy (`/v1/links/preview`)

- [x] dev: YouTube-oEmbed → 200 + Titel/Thumbnail/Provider/Favicon (2026-07-19)
- [x] dev: OpenGraph-Fallback (example.com) → 200 + Titel (2026-07-19)
- [x] dev: unauth → 401 (2026-07-19)
- [x] dev: SSRF 169.254.169.254 + localhost → 204 (2026-07-19)
- [ ] **prod: authentifizierter 200-mit-Daten-Test** über die deployte Staff-SPA
      (worktide.wappler.systems) — URL in einen Dokument-Editor einfügen, Rich-Card
      mit echtem Titel/Thumbnail muss erscheinen. `EGRESS_ALLOW=link_preview` ist auf
      prod gesetzt; steht noch aus, weil prod keine dev-login-Creds hat.
