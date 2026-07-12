# Content-i18n authoring — remaining staff editors

Plan for the staff-side authoring UIs that let an admin translate the
customer-facing, admin-authored content types. Companion to `docs/i18n-plan.md`
(which covers UI-chrome i18n) — this doc is specifically about **content**
translation authoring for the 5 shared entities.

## Background — the model

Content i18n is **client-side**: each entity carries a `translations` JSON
column (`{field:{locale:value}}`); the base column stays the source value.
Staff author per-locale overrides via the shared `<TranslationsFields>`
component (base input bound to the raw field; TranslationsFields edits only the
non-primary locales). The portal / staff SPAs render via `localize(entity,
field)` = `translations[field][activeLocale] ?? entity[field]`. No serializer
normalizer (that corrupts the base column on save).

Two structural tiers matter for *where the editor lives*:

- **Workspace-global config** — one list per workspace, not tied to a customer
  (Industries, Tags, Trackers, Products, MeetingTypes, Newsletters,
  **AgreementType**). Home = a config surface (own page / settings card / the
  place it already appears).
- **Project artifacts** — belong to a `Project` (**PublicForm**). Home = the
  project. The portal shows a customer the forms on *their* accessible projects.

## Already shipped (2026-07-12)

- Backend: `Newsletter` + `MeetingType` made `TranslatableInterface`; migration
  `Version20260712120000` adds the `translations` column. Customer-facing DTOs
  (`PublicBooking`, `PortalMeetingTypes`, `PortalNewsletters`, `PortalForms`,
  `PortalAgreements`) emit `translations`.
- Staff editors (inline TranslationsFields): **Newsletter** (NewslettersPage),
  **MeetingType** (MeetingTypesPage). **Product** already had one.
- Portal display: `localize()` on newsletters, booking (portal + public),
  forms, agreements. Verified de↔en live.

So of the 5, **3 are done** (Newsletter, MeetingType, Product) *functionally*.
Two remain: AgreementType and PublicForm — the two cases below.

**Caveat on the shipped 3:** only the *functionality* is done — they reuse the
existing `TranslationsFields` component as-is (a dashed "Übersetzungen" box that
stacks a per-locale block of inputs below the form). The **UI/UX was not
designed** — see the cross-cutting section below, which applies retroactively to
those 3 as well as to Piece A and Piece B.

---

## UI/UX — inline translation editing (shared component)

`TranslationsFields` is used by **every** inline editor (Newsletter, MeetingType,
Product, Industries, Tags, Trackers) and will be reused by Piece A and by the
form builder's text fields. So its interaction model is a **single cross-cutting
design decision** — redesign it once, every editor improves.

Current state: a dashed box titled "Übersetzungen" appended below the form,
listing each non-primary locale as a sub-block with one input per field + a
"leer = Standardwert" hint. Functional, undesigned.

Context that shapes the choice: only **de + en** today (primary = workspace
locale), **2–3 short text fields** per entity — but the form builder (Piece B)
will have **many** fields + per-field labels.

**DECIDED (2026-07-12): language-dropdown selector.** The chosen model is
"edit the fields for the selected language," with a **language dropdown** as the
selector (a variant of the tab pattern below; dropdown scales past 2 languages
better than tabs and matches the portal's existing language `<select>`).

How it works:
- A `Sprache: [ Deutsch (Standard) ▾ ]` dropdown scopes **only the translatable
  fields**. Selecting a language makes those inputs edit that locale's value;
  the primary/base language ("Standard") edits the base columns, other locales
  edit the `translations` override (placeholder = base value).
- **Non-translatable fields** (slug, duration, availability, …) stay visible and
  always edit the base — they're outside the dropdown's scope.
- Consequence: each editor collapses its current "base input + separate
  Übersetzungen box" into **one language-aware field group**. So the shared
  component's contract changes: it owns base values *and* the translations map
  for its declared fields, keyed off the selected language + primary locale.

Rejected alternatives (kept for context):
- *Language tabs* — same model, tab strip instead of dropdown; crowds with more
  languages.
- *Per-field language toggle* (🌐 per field) — compact for 1–2 fields, fragments
  in the builder.
- *Refined stacked section* — collapsible list of all locales' inputs; least
  change but doesn't scale.

This lands in the shared component, so all existing inline editors get the new
UX and Piece A / Piece B build on it.

**Reconcile with 2026-07-12 pull:** the shared editors (`NewslettersPage`,
`MeetingTypesPage`, `ProductForm`) now also carry **tag inputs** (new tag
system). The dropdown redesign must sit alongside those, not replace them — a
layout concern only, no conflict.

---

## Piece A — AgreementType: translatable, stays global, edited where it is

**Decision:** AgreementType stays **workspace-global config**, surfaced where it
is today (the type list in each customer's *Verträge* tab). We do **not** build a
separate admin page or move it. We only add **translation authoring** for its
`name` + `description`, edited inline — clearly marked **workspace-wide** so
nobody mistakes it for a per-customer change (a rename changes the type on every
customer's rows).

**Current state (`worktide-web/src/pages/customers/CustomerAgreementsTab.tsx`):**
- `useList('agreement_types')` → `visibleTypes` rendered as rows.
- The existing pencil (`openEdit(type)`) edits the **CustomerAgreement** for that
  type (status / signedOn / validUntil / reference / attachment) — NOT the type.
  So type editing needs a **new, separate** affordance.

**Approach (DECIDED: header button):**
1. A header **"Vertragsarten verwalten (arbeitsbereichsweit)"** button above the
   type list opens a small manager (list of types → pick one) → a **distinct**
   dialog for the AgreementType itself: `name`, `description`, +
   `<TranslationsFields fields={[{key:'name'},{key:'description'}]}>`. Chosen over
   a per-row control to avoid two pencils per row (the existing one edits the
   customer's agreement) and to frame it honestly as global config.
2. Save via `PATCH /v1/agreement_types/{id}` with `{name, description,
   translations}` (merge-patch). Backend CRUD already exists — **no backend
   work**. `AgreementType` already has the trait + `translations` column.
3. Label the dialog explicitly workspace-wide; on save invalidate the
   `agreement_types` list.
4. Display: wrap `type.name` in `useLocalize()` in the tab (portal already
   localizes via the remapped `type` key).

**Model confirmation:** `AgreementType` is the global catalog; one type has many
`CustomerAgreement` contracts (different clients), each rendered in that client's
language via the type's `translations`. That's the two-layer model — aligned.
**OPEN:** "different languages" here means the *type name/description* per client
(done). If the **contract content** (line-item descriptions, the agreement
document/PDF) must also exist per-language, that's out of current scope —
`CustomerAgreement`/line-items aren't translatable and documents are uploaded
files; would be a separate feature.

**Effort: small** — one manager + one dialog, no nav change, no backend.

---

## Piece B — Questionnaires (PublicForm): GLOBAL forms builder + client distribution

**DECIDED (2026-07-12) — supersedes the earlier "Project tab" design.**
Questionnaires are a **workspace-global staff resource** (own "Formulare" nav
entry + builder), NOT a per-project tab. A form is **distributed to 0..N
clients**: to several, to one, or to none. **None → staff-only** (shows in no
portal; still usable via the public slug link / internally). Each recipient
client sees it in the portal, localized to their language.

**⚠️ This is a data-model change, not just placement.** Today `PublicForm.project`
is a **required** FK, portal visibility comes from the customer's accessible
*projects* (`findEnabledForPortalProjects`), and a submission creates a Task in
`form.project`. The new model is form-scoped-to-workspace + assigned-to-customers.
Backend deltas:

1. **Scope** — form becomes workspace-global. `project` becomes **nullable**,
   repurposed only as the *task-landing target* (not visibility/home).
2. **Recipients** — add a **form ↔ Customer many-to-many** (`form_recipients`
   join table). Empty set = staff-only.
3. **Portal visibility** — replace `findEnabledForPortalProjects()` with
   `findEnabledForPortalCustomer(customer)` via the recipients relation.
4. **Submission → Task landing** — **DECIDED: (a) + (c).** The form has an
   optional target project ("Aufgaben anlegen in: [Projekt ▾]"); if set, a
   submission creates a Task there; if unset, **no Task** — the submission is
   still stored in `PublicFormSubmission` and read via the submissions inbox
   (below). (b) per-client routing is a later enhancement.
5. **Public slug** `/v1/forms/{slug}` still works independent of recipients — a
   global form is fillable by anyone with the link (Task lands per #4).

**Home/UI:** a global **"Formulare"** list + builder (new nav entry), each form
carrying an **"An Kunden senden"** recipients picker (0..N customers). Builder
content unchanged from before — metadata + schema/field/logic/calc + i18n — only
the project-binding is replaced by client distribution.

**i18n, two levels (unchanged):**
- Top-level `title`/`description`/`successMessage` → the entity `translations`
  column via the shared language-dropdown component. Portal display already wired
  (incl. the post-submit success message).
- **Per-field labels** (block `label`, select options, matrix `rows`, page
  titles) live in the `schema` JSON. **DECIDED (B3): inline per block** —
  each block carries `labelI18n: {locale: value}` (+ option/row equivalents);
  `FormSchemaNormalizer.toClientSchema()` **passes them through** (whitelist,
  don't strip); the portal/public renderer localizes per block **client-side**
  via `localize(block, 'label')` — consistent with the rest of content-i18n (no
  server overlay → no corrupt-on-save). The builder edits them through the same
  form-level language dropdown (switch language → every label input rebinds).

**Phasing:**
- **B0** — backend model change: nullable `project`, `form_recipients` M2M,
  portal-by-customer lookup, submission-landing logic (optional target project,
  no-task-if-unset). Migration.
- **B1** — global "Formulare" list + metadata editor + recipients picker +
  optional target-project picker + top-level translations. Moderate.
- **B1.5** — **submissions inbox** (staff UI over `PublicFormSubmission`; the API
  resource exists, no UI does). Required by the no-task path.
- **B2** — schema/field/logic/calc builder. Large.
- **B3** — per-field label i18n (inline `labelI18n` + normalizer pass-through +
  client localize).

**Effort: large+** (now includes a backend model migration in B0).

---

## Piece E — Per-contract content translation (CustomerAgreement layer)

**DECIDED (2026-07-12):** beyond the global type name/description (Piece A), the
**contract content itself is translatable, per contract** — the overrides live on
the per-customer instance (`CustomerAgreementRevision` / `AgreementLineItem`),
NOT on the global `AgreementType`.

**Translatable content (per contract) — TEXT only, this plan:**
- `AgreementLineItem.description` (the priced lines) — primary.
- Likely `CustomerAgreementRevision.notes` and `reference`.

**✅ E1 DONE** (2026-07-12): backend `69710d5`, portal `f5223d2`, web `26f0a39`.
Line items are now editable AND translatable — this also **built the missing
line-item authoring UI** (they had no editor / API-write path before): a
"Positionen" editor in the Verträge tab (add/edit/delete + a description-language
dropdown), a `PUT .../agreements/{slug}/line-items` endpoint editing the in-force
revision in place, and portal `localize()` on line descriptions. Verified live.

**Approach (E1, text):** add `TranslatableTrait` + `translations` column to
`AgreementLineItem` (+ revision fields as needed); migration. Staff author via
the language-dropdown component in the contract/line-item editor;
`PortalAgreementsController::agreementDto` emits per-line `translations`; portal
`AgreementsPage` localizes line items via `localize()`.

**Per-locale DOCUMENTS (the PDF) → handed to the File-system owner (2026-07-12).**
Doing it right means adding `locale` + `variantOf` to the shared, actively-developed
`File` entity + `PortalFilesController` — so it was handed to the file-system owner
as a feature brief, **`docs/per-locale-documents-plan.md`**, rather than edited here.
Once the File variant API lands, the agreement-document side
(`CustomerAgreementRevision.file`, E2) gets wired to it. Original note below.

**Per-locale DOCUMENTS (the PDF) → tracked in portal roadmap §8.** A real
per-language contract *document* means one file per locale (staff upload DE + EN,
portal serves the viewer's locale). That's **file management, not text i18n**, so
it belongs with the §8 "Wissen & Assets" work (`docs/customer-portal-ideas.md`
§8), not here. Tracked there as "per-locale document variants." **Note (2026-07-12
pull):** the file/folder system now exists — `Folder`/`FolderService`/
`FolderController`, `PortalFilesController`, `File`, `CustomerFilesTab`,
`src/lib/files.ts` — so E2 builds on that, it isn't greenfield.

**Dependency/risk:** authoring per-line-item translations needs a staff UI that
*edits line items* in the first place. Verify where line items are authored today
(CustomerAgreementsTab edits status/dates/reference/attachment — line-item
editing may be limited or absent); if absent, E1 also needs that base editor.

**Effort: medium (E1).** Own milestone; distinct from Piece A. Documents = §8.

---

## Piece C — /kalender-sync straggler (tiny)

`worktide-web/src/pages/meetings/CalendarSyncPage.tsx` is only partially
translated (lines ~79–138 still hardcoded German: "Kalender verbinden", "Zuletzt
synchronisiert", "Aktualisieren", "Verbinden", "Kalenderverbindung entfernen",
…). Convert to `t()` + add `de`/`en` keys. Unrelated to content i18n but tracked
here. **Effort: tiny.**

---

## Summary / order of attack

| Piece | What | Effort | Backend? |
|-------|------|--------|----------|
| ~~**UX**~~ | ✅ **DONE** (`worktide-web 21b2a4b`) — `LocalizedFields` dropdown; all 6 editors migrated, old component deleted, verified de↔en live | — | none |
| ~~A~~ | ✅ **DONE** (`worktide-web 60c682c`) — header "Vertragsarten verwalten" → type manager → LocalizedFields editor; PATCH verified live | — | none |
| ~~C~~ | ✅ **DONE** (`worktide-web 60c682c`) — CalendarSyncPage strings → `calendar_sync.*` | — | none |
| ~~B0~~ | ✅ **DONE** (`worktide 62eede5`) — nullable `project`, `public_form_recipients` M2M, portal-by-customer, task-only-if-project; suite green | — | migration ✔ |
| ~~B1~~ | ✅ **DONE** (`worktide-web 578d89b`) — /formulare list + editor (metadata + recipients + translations); portal visibility verified live | — | none |
| B2 | Form builder ✅ **COMPLETE** — fields (`82e9ee8`); matrix rows + sections (`97c78f4`); branching-logic show/hide editor (`83bbffd`, v1→v2 upgrade + id-targeting fix); **calc/computed-fields editor** (`9def082`, n-ary `{op,args}` AST). All verified live incl. `total = qty × price` computing in the portal | done | none |
| ~~B3~~ | ✅ **DONE** (backend `48639f9`, portal `2e2f5f5`, web `91b7dcb`) — inline `labelI18n` per block, normalizer pass-through, portal localizes, staff editor via Fields-language dropdown; verified live | — | done |
| ~~B3+~~ | ✅ **DONE** (backend `1b313a7`, portal `e3a0542`, web `1b2443c`) — **option label i18n** (`optionsI18n`) end-to-end (staff editor + portal render; value stays base); matrix **row** labels (`rowsI18n`) carried by backend+portal but staff row-authoring deferred (no base-rows editor yet) | — | done |
| ~~B1.5~~ | ✅ **DONE** (`worktide-web 231166b`) — per-form submissions inbox (timestamp, task badge, payload); verified live | — | none |
| E1 | Per-contract text translation (line-item `description` etc., + editor + portal) | medium | **migration** |
| — | Per-contract **documents** (per-locale PDFs) → **portal roadmap §8**, not here | — | — |

Suggested order: **UX** first (the shared component A and the shipped-3 all use),
then **A + C** (quick, close the "everything else" bucket), then **B** (B0→B3,
incl. B1.5 inbox) and **E1** as their own milestones. Per-locale documents live
in §8.

## Open decisions

- ~~UX pattern for `TranslationsFields`~~ **DECIDED: language-dropdown selector.**
- ~~Piece A placement~~ **DECIDED: header "Vertragsarten verwalten" button.**
- ~~Piece B home (project tab vs. ?)~~ **DECIDED: global "Formulare" resource,
  distributed to 0..N clients (0 = staff-only).** Supersedes the project-tab plan.
- ~~Piece B3 storage~~ **DECIDED: inline `labelI18n` per block, client-localized.**
- ~~AgreementType per-language scope~~ **DECIDED: contract content is also
  translatable, per contract** → **Piece E1** (text). Type name/description =
  Piece A. Per-locale **documents** → portal roadmap §8, not here.
- ~~Piece B submission landing~~ **DECIDED: (a) optional per-form target project
  + (c) no task if unset** → adds the **submissions inbox** (B1.5).

**All decisions resolved.** Remaining verification before building E1: confirm a
staff line-item editor exists (else E1 also needs it).
