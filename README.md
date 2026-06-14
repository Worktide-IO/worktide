# Worktide

> Open-source project, task, time and CRM management — a functional hybrid of
> awork and Redmine, built on Symfony 8 with a clean REST API.

Worktide is being developed as a self-hostable replacement for the
WapplerSystems agency stack: project tracking, task boards, time logging,
recurring schedules, autopilot alerts, workflow automation, document spaces,
and (planned) a per-tenant customer database with TYPO3 client portal.

## Status

Phase 2 fully landed + awork-sweep closed; **Phase 3 (CRM) underway**.
The data model has been validated against a real awork account: 10 picked
projects with 218 tasks were imported and tested through the public API,
including voter isolation and webhook delivery.

| Block | Feature | Status |
|---|---|---|
| Phase 1 | Workspace/Project/Task/TimeEntry foundations, JWT auth, voters | ✓ |
| B1 | Polymorphic comments + activity feeds | ✓ |
| B2 | Task lists + checklist items | ✓ |
| B3 | Task dependencies + project milestones | ✓ |
| B4 | Polymorphic file attachments + versioning | ✓ |
| B5 | Project + Task templates | ✓ |
| B6 | Workflow automation + recurring schedules | ✓ |
| B7 | Workforce: Teams, Absences, UserCapacity, TypeOfWork | ✓ |
| B8 | Reporting endpoints + Autopilot alerts | ✓ |
| B9 | Wiki-style documents (Spaces, Contributors, hierarchical) | ✓ |
| B10 | Outbound HMAC-signed webhooks with async retry | ✓ |
| B11 | Granular per-role permissions with workspace overrides | ✓ |
| Sweep | Personal Access Tokens, Workspace Invitations, Active Timer + Private Tasks, Time Tracking Settings | ✓ |
| CRM-1 | Customer + Contact entities, Project.customer FK, awork-companies backfill | ✓ |
| CRM-2 | CustomerSystem (TYPO3/WP/...) + ServiceSubscription with auto-computed next-billing | ✓ |

Code at this point: 46 entities, 14 enums, 17 API controllers, 12 voters,
a comprehensive DataFixtures seed, a Doctrine middleware for UUID-FK binding,
a domain-event log auto-populated from Doctrine, and an awork importer.

## Stack

- **PHP 8.4** / **Symfony 8.1** / **API Platform 4.3**
- **MySQL 8.0** via Doctrine ORM 3.6
- **UUIDv7** primary keys (`symfony/uid`) stored as `BINARY(16)`
- **JWT** auth via `lexik/jwt-authentication-bundle` + refresh tokens via
  `gesdinet/jwt-refresh-token-bundle`
- **Flysystem** for file storage (local adapter by default, S3-swappable)
- **Symfony Messenger** with Doctrine transport for async webhook delivery
- **DDEV** for local development

## Architecture highlights

### Multi-tenancy by default
Every domain entity uses `WorkspaceScopedTrait`. Workspaces isolate users,
projects, tasks, billing, automations — Voters enforce membership-based
authorization at API-Platform-resource level.

### Polymorphic targets
Comments, files, custom-field values and webhooks attach to multiple
aggregate types via `target` enum + `targetId` UUID rather than per-type
join tables — fewer schemas, simpler filters.

### Domain event log
`DomainEventEmitterSubscriber` listens on Doctrine's `onFlush` and writes an
immutable row to `domain_events` for every tracked entity (~30 currently).
Same events feed:
- the activity-feed UI,
- workflow automations,
- and outbound webhook subscribers (B10).

### Outbound webhooks
Workspace-scoped subscribers receive HMAC-SHA256-signed POSTs when matching
events fire. Auto-deactivate after consecutive failures; delivery log keeps
the full attempt history.

### awork importer
Read-only Bearer-token snapshot from `api.awork.com`, idempotent replication
into Worktide via `(externalSource='awork', externalId='aw:<uuid>')` —
seconds→minutes conversion, per-project taskstatuses collapsed to workspace
scope, multi-assignment preserved, external contacts skipped.

## Local development

### Bring up the stack

```bash
ddev start
ddev composer install
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console doctrine:fixtures:load --no-interaction
```

Frontend: <https://worktide.ddev.site>
API: <https://api.worktide.ddev.site/v1/>

### Get a JWT

```bash
TOKEN=$(curl -s -X POST https://api.worktide.ddev.site/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"sven@worktide.test","password":"demo"}' \
  | jq -r .token)
```

Demo users (all password `demo`): `sven@worktide.test` (Owner),
`alex@worktide.test`, `mira@worktide.test`, `tom@worktide.test`.

### Sample requests

```bash
# List projects in the demo workspace
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://api.worktide.ddev.site/v1/projects" | jq

# Create a task
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/ld+json' \
  -d '{"workspace":"/v1/workspaces/<uuid>","project":"/v1/projects/<uuid>","identifier":"WORK-100","title":"Example","status":"/v1/task_statuses/<uuid>","priority":"normal"}' \
  https://api.worktide.ddev.site/v1/tasks | jq
```

OpenAPI docs are exposed at <https://api.worktide.ddev.site/v1/docs>.

### Async workers

The Messenger `async` transport carries outbound webhooks (and any future
deferred work). Consume it locally with:

```bash
ddev exec php bin/console messenger:consume async -vv
```

### Import data from awork

Read-only snapshot followed by an idempotent replicate into a dedicated
workspace.

```bash
# Token must already exist in ~/.config/awork-token (chmod 600).
bash var/awork-snapshot/pull.sh                  # pull → var/awork-snapshot/*.json
ddev exec php bin/console app:awork:import       # import into a "WapplerSystems (awork)" workspace
ddev exec php bin/console app:user:reset-password wappler@wappler.systems demo
```

See `src/Command/AworkImportCommand.php` for the field-by-field mapping.

### Useful console commands

```bash
ddev exec php bin/console debug:router            # all routes
ddev exec php bin/console doctrine:schema:validate
ddev exec php bin/console app:tasks:run-schedules # materialise due TaskSchedules
ddev exec php bin/console app:autopilots:evaluate # fire alert rules
```

## Roadmap

Short-term:
- License decision (currently TBD)
- Optional: Global search (needs FTS engine), Shared download links

Phase 3 (CRM + Integrations):
- ✓ CRM-1: Customer + Contact entities, Project.customer link (awork
  companies imported as Customers).
- ✓ CRM-2: CustomerSystem (TYPO3 / WordPress instances per customer) +
  ServiceSubscription with cents-precision pricing, BillingCycle enum,
  and an auto-computed nextBillingOn that drives the upcoming-billing
  query.
- CRM-3: TYPO3 customer portal so clients can see booked systems +
  services + invoices (via Contact.linkedUser).
- CRM-4: Invoice + Billing cycle (turn nextBillingOn into actual invoices).
- OAuth-per-workspace external system links (Lexoffice, GitLab, …)
- Cross-agency project collaboration via guest workspaces
- Document vault (AV-contracts etc.) with retention rules
- External SSO (Keycloak / Azure AD)
- Inbound webhooks + first-class GitLab/GitHub issue mirroring

## Project layout

```
src/
├── Command/                      # Console commands (app:awork:import, …)
├── Controller/Api/               # Custom REST endpoints beyond API Platform CRUD
├── DataFixtures/                 # Demo data: projects, tasks, automations, docs, webhooks
├── Doctrine/Middleware/          # UuidBindingMiddleware — auto-binds UUID FK params
├── Entity/                       # 46 entities + 14 enums
├── Event/                        # GenericEntityChangedEvent + typed semantic events
├── EventSubscriber/              # DomainEventEmitter, WebhookDispatcher, Auditable
├── Message/  & MessageHandler/   # Async jobs (currently: SendWebhookMessage)
├── Repository/                   # Doctrine repos with custom queries
├── Security/Voter/               # 12 voters with delegation via AccessDecisionManager
├── Service/                      # FileStorage, AutopilotEvaluator, …
└── State/                        # API Platform state processors
config/
├── packages/                     # api_platform, security, messenger, doctrine, …
└── routes/                       # API Platform host-scoped routing
migrations/                       # Doctrine migrations (one per block)
var/awork-snapshot/               # gitignored; pull.sh writes JSON dumps here
```

## License

To be determined. Until a license is added, the source is provided for
inspection only — no redistribution rights are granted.
