# Internationalization (i18n) plan — Worktide

Goal: make the whole product translatable, **German + English** for now. Two axes:
**UI chrome** (labels/buttons/emails/notifications → message catalogs) and **content**
(staff-authored data → per-record translations). Scope decision: **both**.

## Confirmed decisions (2026-07-11)

1. **Keys:** semantic English identifiers (`nav.tasks`, `action.save`), not German-text-as-key.
2. **Default/fallback locale:** **English** at app level; **`Workspace.locale` keeps its `de` default**
   (existing workspaces stay German). Resolution chain: user pref → workspace.locale → app default (`en`).
3. **Content authoring:** all content types get DE/EN authoring (not just customer-facing).
4. Languages: `de`, `en` (`app.supported_locales`).

## What already exists (reuse, don't rebuild)

- **Backend locale:** `Workspace.locale` (default `de`), `User.preferredLanguage`; `LocaleResolver`
  (`src/Service/I18n/LocaleResolver.php`, chain user→workspace(`X-Workspace-Id`)→default; ignores
  Accept-Language today). `symfony/translation` installed, `default_locale: en`, `translations/` EMPTY,
  zero `->trans()` usage. No `LocaleSubscriber`.
- **Content storage:** `TranslatableTrait` + `translations` JSON column on **15 entities**
  (ProjectTemplate, Product, Tracker, CustomFieldDefinition, TaskStatus, ProjectStatus, AgreementType,
  PublicForm, CustomFieldOption, ProjectType, TaskTemplate, TypeOfWork, SavedReply, Industry, Tag).
  **`getTranslation()` has ZERO call sites** — stored/editable but never rendered.
- **Staff content-translation UI:** `src/lib/languages.ts` (`localize`, `useActiveLocale`,
  `preferredLanguage` from `/me/profile`) + `TranslationsFields.tsx` (used in industries/tags/trackers/products).
- **Preference UX:** staff Profile `Sprache` picker works; portal has a switcher (currently a DEAD setting).

## Gaps

- **UI strings 100% hardcoded German**, greenfield in both SPAs (no i18n lib / no `t()`):
  web ~116 files + 298 toasts + 32 label maps + ~40 `de-DE` date sites + nav `meta.label`s;
  portal ~25 files + nav + ~18 date sites. Portal switcher changes no text.
- **`Contact.locale` MISSING** — blocks localizing everything sent to customers (portal DTO labels,
  newsletter/booking/invitation mail). Booking/WorkspaceInvitation/ProjectShareInvitation carry no locale.
- **Server-rendered German:** 11 email templates + hardcoded German subjects; 6 notification resolvers
  (German, PERSISTED on `Notification.title/body` → must localize at build-time per recipient);
  **~57 German status-label literals across 12 Portal controllers** (served to Contacts w/ no locale).

## Language resolution per surface

| Surface | Source |
|---|---|
| Staff SPA | `User.preferredLanguage` → workspace → en (reuse `useActiveLocale()`) |
| Portal SPA (authed) | portal user `preferredLanguage` → workspace → en (wire the switcher) |
| Public pages (booking/unsubscribe) | `?lang=` baked into server-generated email link → `navigator.language` → en |
| Backend request-scoped | existing `LocaleResolver` (user pref → workspace via `X-Workspace-Id` → default), surfaced to the translator by a new `LocaleSubscriber`. NOTE: `LocaleResolver` deliberately IGNORES `Accept-Language` (keeps responses cacheable per user+workspace), so SPAs do NOT send it — the backend already knows the stored preference, and the SPA UI language is driven from the same stored preference, so they stay aligned. |
| Backend recipient-scoped (mail/notifications) | NEW recipient resolver: recipient.locale (User.preferredLanguage or Contact.locale) → workspace → en |

## Phases (each independently shippable)

- **Phase 0 — Foundation:** `Contact.locale` (+ migration) + capture locale on Booking/Invitations;
  recipient-aware locale resolver; `LocaleSubscriber` + `Accept-Language` from both SPAs; add
  `i18next`+`react-i18next` (web via Refine `i18nProvider`) wired to the existing preference; empty
  `de`/`en` catalogs; make the portal switcher call `i18n.changeLanguage`.
- **Phase 1 — High-visibility UI:** 32 label maps + nav + 298 toasts (web), portal nav + label maps;
  convert the 57 Portal controller label maps to translator-resolved labels (needs Accept-Language +
  Contact.locale).
  - ✅ **Backend done** (commit `41b0f6c`): all 12 Portal controllers now resolve `*Label` fields via
    `$translator->trans('label.<domain>.<value>')`; catalogs hold priority/invoice/agreement/
    subscription/offer/goal/idea/proposal status, billing, {brainstorm,idea,proposal} origin, social
    status, system env/status, incident kind, activity, actor, and error keys. Verified de↔en on every
    portal endpoint. **Two foundation fixes shipped here:** (1) `LocaleSubscriber` pushes the locale
    into the translator itself — it runs at prio 6 (after firewall, to see the user) which is *after*
    Symfony's `LocaleAwareListener` (15), so without this every `->trans()`/email `|trans` silently used
    the default locale; (2) `LocaleResolver` falls back to the portal user's linked-contact→customer→
    workspace locale when no `X-Workspace-Id` header is present (portal never sends it).
  - ⏳ **Frontend remaining:** web 32 label maps + nav `meta.label` + 298 toasts; portal nav +
    `SettingsPage` maps.
- **Phase 2 — Full UI sweep:** remaining inline JSX (both SPAs), validation, locale-aware
  dates/numbers/currency (+ FullCalendar locale), email templates + subjects, notification resolvers
  (build title/body via translator per recipient).
- **Phase 3 — Content i18n rendering:** wire `getTranslation()` into API Platform serialization
  (normalizer swaps base→translated per active locale, base fallback); extend `TranslatableTrait` +
  `TranslationsFields` editor to the ~10 more content entities (Newsletter, NewsletterIssue,
  NewsletterTemplate, Document, MeetingType, CustomerGoal, ProjectProposal, SavedReply, …);
  localize content-bearing emails.
- **Phase 4 — Polish & QA:** public-page language, digest emails, full DE/EN end-to-end pass.

## Effort

Large — multi-week, dominated by string extraction (low-thousands). Phases 0–1 are the high-leverage
start; content i18n (Phase 3) is the long tail but has the storage half already built.

## Anchors (from the 3-repo survey)

- web: `src/lib/languages.ts`, `src/components/TranslationsFields.tsx`, nav in `src/App.tsx:118-303`,
  label maps in `src/lib/{catalog,research}.ts` + per-page maps, dates in `src/lib/{money,time}.ts` +
  ~20 sites, FullCalendar `pages/calendar/CalendarPage.tsx:219`.
- portal: switcher `src/components/PortalLayout.tsx:82-101`, `src/lib/portal.ts` (`setLanguage`,
  `LANGUAGE_LABELS`), `SettingsPage.tsx` label maps, public pages (Booking*, NewsletterUnsubscribe).
- backend: `src/Service/I18n/LocaleResolver.php`, `TranslatableTrait`/`TranslatableInterface`,
  `templates/email/*.twig`, `src/Notification/Resolver/*`, Portal controllers' German label maps,
  `config/packages/translation.yaml`, `config/services.yaml` (`app.supported_locales`).
