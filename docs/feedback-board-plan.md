# Feedback & Requests board — design plan

Goal: give **every Worktide user** — agency staff *and* their portal clients — a low-friction
way to report a **bug**, request a **feature**, or ask for a **UI/UX change**, and to follow it
through to resolution. Submissions land in one shared, Worktide-triaged backlog; the Worktide team
works them; submitters (and everyone else) watch progress and can discuss.

This is the "protocol & send us bugs / feature / UX requests" feature. It is realized natively on
Worktide's own ticketing primitives rather than as a foreign service or third-party tool.

Companion to `docs/customer-portal-ideas.md` §6a (Roadmap / Feature-Vorschau), which gestured at a
`RoadmapItem`/feature-voting concept — this doc supersedes that gesture for the *inbound feedback*
half.

## Confirmed decisions (2026-07-16)

Locked with the user during brainstorming:

1. **Audience & direction:** *everyone* → the **Worktide vendor**. Both staff (worktide-web) and
   portal clients (worktide-portal) submit; Worktide triages centrally.
2. **Loop depth:** **full loop** — status/progress *and* threaded replies visible to the submitter.
3. **Hub shape:** *"a project with tickets"* — realized as a **shared, cross-tenant feedback project**
   built from `Task` / `Tracker` / `TaskStatus` / `Comment`, **not** a separate hub app.
4. **Reach for v1:** **cloud instances first.** Only Worktide's own vendor-hosted (Coolify) backend.
   → no phone-home, no instance identity, no egress module, no consent UX in v1 (see *Deferred*).
5. **Backlog scope:** **one global shared board.** A user in agency A sees (anonymized) requests
   from agency B. Single central product-feedback backlog.
6. **Anonymity:** requests, replies and progress are **anonymized** to all users. Only **Worktide
   platform admins** (`ROLE_SUPER_ADMIN`) see who submitted / who replied.
7. **Client visibility toggle:** a **per-workspace** switch that hides the board from that
   workspace's **portal clients only** — the agency's own staff always keep access. **Owned by the
   agency admin** (self-service, reusing the portal-feature-flag machinery).
8. **Replies:** **anyone who can see the board can reply to any ticket** (public discussion), subject
   to the same anonymized display.
9. **Voting:** **no** upvotes in v1 — a plain browsable list with status. (Reconsider later; the
   `Idea`/`IdeaVote` pattern is available if wanted.)

## The model

- A dedicated **platform workspace** (Worktide-owned) contains one **feedback `Project`**.
- Each submission is a **`Task`** in that project. A "ticket" already *is* a Task everywhere in
  Worktide, so status, priority, comments and the whole portal rendering come for free.
- **Category** = the existing `Tracker` enum (`src/Entity/Tracker.php:27` — Bug/Feature/Support/…);
  the feedback project is seeded with three trackers: **Bug**, **Feature request**, **UI/UX change**
  (icon keys `bug`, `sparkles`/`life-buoy`, `layout`).
- **Progress** = the feedback project's `TaskStatus` set, e.g. `New → Triaged → Planned →
  In progress → Done / Declined`. The status *label* is what the public board shows.
- **Attribution** lives on a small sidecar (see below), never on the public projection.

### Recommended: reuse `Task` + a `FeedbackSubmission` sidecar

Tickets are real `Task`s in the feedback project; a 1:1 **`FeedbackSubmission`** entity carries the
sensitive attribution and context that must never reach the public board:

- `task` (OneToOne), `submitterUser` (nullable), `submitterContact` (nullable),
  `originWorkspace` (the workspace the submitter came from), `category` (mirror of Tracker for
  convenience), and auto-context: `route`/`path`, `appVersion`, `userAgent`, `submittedFromApp`
  (staff|portal). All admin-only.

**Why reuse Task rather than a standalone entity:** Worktide super-admins already bypass
`WorkspaceScopeExtension` (it short-circuits `ROLE_SUPER_ADMIN` → all tenants), so **the Worktide
team triages the backlog through the normal staff Tasks UI on the feedback project — zero new admin
UI to build.** Comments, statuses, trackers, filtering, search all work out of the box.

**Alternative considered — a dedicated `FeedbackItem` entity** (decoupled from Task): cleaner
cross-tenant/public semantics (no chance of a feedback ticket leaking into someone's normal task
lists, no fighting workspace-scoping), but you then build a triage UI from scratch. Rejected for v1
on the "minimize build, triage in existing tools" principle; revisit if the Task reuse proves leaky.

## Visibility & anonymization (the crux)

The board is **cross-tenant and public within Worktide**, which is exactly what
`WorkspaceScopeExtension` (the multi-tenant boundary the whole security model rests on) is built to
prevent. So the board is **never** exposed through API-Platform resources. Instead:

- **Curated, read-only, cross-tenant endpoints** (plain `#[Route]` controllers) query the feedback
  project directly, bypassing scope the same controlled way the 16 portal controllers and the public
  booking slug lookup already do. They return an **anonymized DTO**: title, category, status label,
  createdAt, and the reply thread — **no submitter, no origin workspace, no names, no context**.
- **Anonymized display model** for the thread: each author maps to a role label, never an identity —
  **"Worktide Team"** (platform staff/super-admin) vs **"A user"** (anyone else) vs **"You"** for the
  viewer's own comments. The original reporter is shown as **"Reporter"** (still no identity). Because
  commenting is public and cross-tenant, this anonymization applies to *commenters too*.
- **Worktide platform admins (`ROLE_SUPER_ADMIN`)** see full identity + context — via the normal
  staff Tasks UI (Task detail shows the `FeedbackSubmission` sidecar) — for triage and follow-up.
- **The client toggle:** a new portal feature key `feedback` in
  `PortalAccessResolver::FEATURE_KEYS`, gated per workspace via `Workspace.settings.portal.features`
  (+ honoring `Contact.portalHiddenFeatures`). Agency admins flip it in the existing staff
  **Einstellungen → Kundenportal** UI. Off → portal nav item hidden + endpoint 403. **Staff access is
  independent of this toggle** and always on.

## What already exists to reuse

Backend:
- **Ticket = Task**, category = `Tracker` (`Tracker.php:27`), status = `TaskStatus`, priority — all present.
- **Portal ticket pattern** — `src/Controller/Api/Portal/PortalTicketsController.php` is the exact
  template for curated DTOs + hand-scoped reads + comment create; copy its shape for the feedback
  endpoints.
- **Comments** — polymorphic `Comment` (`CommentTarget` + `targetId`, body field `content`, `author`
  a NOT NULL `User`). Portal contacts have a `ROLE_PORTAL` `User` via `Contact.linkedUser`, so
  authorship works for both audiences.
- **Notification pipeline** — `DomainEventLog → NotificationDispatchSubscriber → resolvers →
  Notification → Mercure/bell/inbox`. A reply notifies the reporter (and optionally thread
  participants) by adding **one `NotificationType` case + one resolver** — no transport plumbing.
- **Super-admin cross-tenant bypass** in `WorkspaceScopeExtension` → free triage UI.
- **Portal feature-flag machinery** — `PortalAccessResolver::FEATURE_KEYS` /
  `assertFeatureEnabled()`, `Workspace.settings.portal.features`, `Contact.portalHiddenFeatures`.

Frontend — staff (worktide-web):
- Global-overlay mount point in `src/components/AppLayout.tsx` (overlays are siblings of the shell,
  ~lines 134-138) + header action slot next to `NotificationBell` (~line 128).
- `QuickAddDialog.tsx` — the model for a global, keyboard-openable submit dialog; shadcn
  `Dialog`/`Input`/`Textarea`/`Select` in `src/components/ui/`.
- API via a thin `src/lib/feedback.ts` service on the shared `api` axios instance (model:
  `src/lib/notifications.ts`). i18n: flat semantic keys in `src/locales/{de,en}.json` (`feedback.*`).

Frontend — portal (worktide-portal):
- Header action slot in `src/components/PortalLayout.tsx` (~line 92) + nav array (~lines 42-57,
  feature-gated on `me.features.feedback`).
- `pages/IdeasPage.tsx` (submit + browsable list) and `NewTicketPage.tsx` (create form) are the
  templates. No shadcn — hand-rolled modal / inline form (see the `ProposalsPage` inline feedback
  form). API via `portalApi` in `src/lib/portal.ts` (~line 498). i18n `feedback.*` in
  `src/locales/{de,en}.json`.

## API surface (v1)

Staff (`ROLE_USER`, any workspace):
- `GET  /v1/feedback` — anonymized board list (filter by category/status; newest first / by status).
- `GET  /v1/feedback/{id}` — anonymized detail + reply thread.
- `POST /v1/feedback` — submit `{category, title, description}` (+ auto-context attached server-side).
- `POST /v1/feedback/{id}/replies` — add a reply (public).

Portal (`ROLE_PORTAL`, gated by the `feedback` feature toggle):
- `GET  /v1/portal/feedback`, `GET /v1/portal/feedback/{id}`,
  `POST /v1/portal/feedback`, `POST /v1/portal/feedback/{id}/replies` — same DTOs, route-is-authz.

All are curated controllers; none is an `#[ApiResource]`. Reads are anonymized; submit stamps the
`FeedbackSubmission` sidecar with identity + auto-context (route, app version, UA, source app).

## Auto-context (attached automatically, admin-only)

On submit the client sends, and the server records on the sidecar: current **route/path**, **app
version** (`AppVersion`), **browser/UA**, **source app** (staff|portal), and server-derived
**origin workspace** + submitter. Makes reports actionable without a round-trip; never shown on the
public board.

## Deferred (designed-for, not built in v1)

- **Self-hosted phone-home.** When self-hosted instances must reach the central board, add a
  vendor-directed outbound rail modeled on the **LLM provider** (`AnthropicLlmProvider` — a fixed
  external base URL + operator key + `isConfigured()` seam + an `EgressModule` gate + metering),
  **not** the operator webhook. Requires a net-new **instance identity** (no license key / instance
  UUID exists today) + a **consent/redaction UX** (portal clients' words + screenshots leaving a
  white-label box to the vendor is the sharpest privacy edge). All out of scope for cloud-first v1.
- **Screenshots.** No screenshot library exists in either SPA. `html2canvas` (DOM snapshot, no
  permission prompt) is the clean add; attachments would need a new `FileTarget` case + an arm in
  `FileUploadController::resolveTargetEntity()`, and — to leave a self-hosted box later — read-back +
  embedding (no presigned-URL flow exists). A v1.1 candidate.
- **Voting / dedup** (Idea/IdeaVote pattern), **public roadmap / changelog**, **CSAT** (see
  customer-portal-ideas §2).

## Open implementation details (settle at plan time)

- Which `TaskStatus` set + trackers to seed in the feedback project, and their public labels (i18n).
- Reply-notification fan-out: reporter only, or all thread participants (dedup-safe resolver).
- Anonymized author labeling for edge cases (a Worktide admin who is also a workspace member).
- Rate-limiting on submit/reply (reuse the public-form limiter pattern).

## Suggested phasing

1. **Backend core** — feedback project + trackers/statuses seed; `FeedbackSubmission` entity +
   migration; curated staff + portal controllers (submit/list/detail/reply) with anonymized DTOs;
   `feedback` portal feature key + agency-admin toggle; functional test asserting cross-tenant reads
   are anonymized and the toggle gates portal clients (mirror `TenantScopingTest` /
   `ProjectShareScopeTest`).
2. **Reply notifications** — new `NotificationType` + resolver → bell/inbox for the reporter.
3. **Staff SPA** — global submit widget in `AppLayout` + a "Feedback" board page.
4. **Portal SPA** — nav entry (gated) + submit + board page.
5. **Polish** — auto-context surfacing in the admin Task view; i18n sweep (de/en).
6. *(later)* screenshots; *(later)* self-hosted phone-home rail; *(later)* voting/roadmap.
