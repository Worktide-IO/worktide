# Plan: per-locale documents in the File system (§8)

**Owner:** whoever maintains the native File/Folder system (Sven).
**From:** the content-i18n workstream. This is a feature brief for *your* area
(`File`/`FileVersion`/`Folder`/`PortalFilesController`/`CustomerFilesTab`/portal
`FilesPage`) — handed over rather than implemented cross-workstream, because it
extends the `File` entity you're actively developing and I don't want to collide
with your design.

## Why

The content-i18n effort is done: admin-authored, customer-facing content is
translatable and the portal renders it in the **viewer's language, falling back
to a base value** (client-side `localize()` — never a server overlay). The last
piece is **documents**: an EN portal user should download the EN contract/
deliverable PDF, DE users the DE one, with a sensible fallback. Tracked in
`docs/customer-portal-ideas.md` §8 ("Sprachvarianten von Dokumenten") and
`docs/content-i18n-authoring-plan.md`.

It builds on the file system you shipped today (folders, `FileVersion` history,
polymorphic `File.target`/`targetId`, portal browse/download/upload).

## The model (recommended)

`File` has no language concept yet. Add two nullable columns:

- **`locale`** (`VARCHAR(8)`, nullable) — the language this file *is* (`de`/`en`);
  `null` = the base/default document.
- **`variantOf`** (`ManyToOne File`, nullable, `ON DELETE CASCADE`) — points a
  language variant at its base `File`. A base file has `variantOf = null`.

A "document" = one base `File` (`variantOf = null`) + 0..N variant `File`s
(`variantOf = base`, each with a `locale`). Variants are **not** listed as
separate documents — they're alternates of the base. This mirrors the
`labelI18n`/`translations` "base + per-locale override, fall back to base"
pattern used throughout content-i18n.

Keep it orthogonal to `FileVersion`: each variant is its own `File` with its own
version history. Don't make locale a dimension of `FileVersion` — versions are
chronological, variants are linguistic.

## Backend

1. **Entity + migration**: `File` gains `locale` + `variantOf`; index `variantOf`.
2. **List** (`PortalFilesController::list` + the staff `File` ApiResource): return
   only **base** files (`variantOf IS NULL`) and expose the available variants on
   each, e.g. `availableLocales: ['de','en']` (or `variants: [{locale, id}]`).
3. **Download** (`PortalFilesController::download`): take the desired locale (a
   `?locale=` param, or the request's resolved locale via the existing
   `LocaleResolver`/`LocaleSubscriber`) and serve the matching variant, **falling
   back to the base** when there's none. Keep presign / auth / customer-scoping
   exactly as-is — **resolve the variant first, then run the access check on the
   resolved file** (never leak a file the base check would deny).
4. **Upload a variant**: reuse your upload path but set `locale` + `variantOf`.

Codebase gotchas:
- API Platform strips the `is` prefix on boolean getters (read `archived`, write
  `isArchived`) — watch it if you add flags.
- `File` is workspace-scoped + polymorphic; a variant must inherit the base's
  `target`/`targetId`/`workspace`/`folder` so scoping holds.
- Portal contacts see only their customer's files — resolve-then-check preserves
  that.

## Frontend

- **Portal `FilesPage`** (`worktide-portal`): for a base file with variants, a
  small language menu/chips; download uses the active portal language
  (`i18n.language`), fall back to base. `worktide-portal/src/lib/localize.ts` has
  reusable `useLocalize`/`useLocalizeMap` helpers.
- **Staff `CustomerFilesTab`** (`worktide-web`): an "Add language version" action
  → upload a variant (locale + file). Languages from `useSupportedLanguages()`
  (`worktide-web/src/lib/languages.ts`).

## Agreement documents (the concrete driver — coordinate with i18n workstream)

The headline case is the **contract PDF** on `CustomerAgreementRevision.file` (a
single `File` FK; see `PortalAgreementsController` `hasDocument`). If `File` is
variant-aware, the agreement doc gets per-locale for free once its `File` has
variants. The i18n workstream owns the agreement layer (per-contract **text**
i18n already shipped: `AgreementLineItem.translations`, the "Positionen" editor,
portal `localize()`) and will wire the agreement-document side to whatever
variant API you land.

## Reference points

- Content-i18n plan + decisions: `docs/content-i18n-authoring-plan.md`.
- §8 note: `docs/customer-portal-ideas.md` (search "Sprachvarianten").
- Portal localize helpers: `worktide-portal/src/lib/localize.ts`.
- Server locale resolution: `LocaleResolver` + `LocaleSubscriber`.
- File surfaces: `src/Entity/File.php`, `FileVersion.php`, `Folder.php`,
  `src/Controller/Api/Portal/PortalFilesController.php`, `FolderService.php`;
  `worktide-web/src/pages/customers/CustomerFilesTab.tsx`, `src/lib/files.ts`;
  `worktide-portal/src/pages/FilesPage.tsx`.

## Suggested order

1. `File.locale` + `File.variantOf` + migration.
2. List returns base-only + `availableLocales`; download resolves locale → variant → base.
3. Staff "add language version" upload.
4. Portal `FilesPage` language menu + locale-aware download.
5. (with the i18n workstream) agreement-document variants.
