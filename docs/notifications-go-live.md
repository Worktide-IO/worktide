# Notification email — Go-Live Checklist

Everything for **§7B (notification channels + email digest)** is built and verified
locally against Mailpit. The code needs no further work to go live — only prod
**environment + infrastructure** configuration. Work top-to-bottom; nothing here
sends real mail until every box is checked (that is by design — the egress gate
and `null://null` transport fail closed).

## 1. Environment variables (set in Coolify / prod env)

| Var | Go-live value | Why |
|---|---|---|
| `MAILER_DSN` | real transport, e.g. `smtp://USER:PASS@smtp.provider.com:587` | Default `null://null` discards all mail. SMTP works with the current build; an API transport (`ses+https://`, `postmark://`, `mailgun+https://`, …) needs its Symfony bridge `composer require`d first — only `symfony/mailer` (SMTP/sendmail/native) is installed today. |
| `EGRESS_ALLOW` | must **include** `email_outbound` (e.g. `email_outbound` or `email_outbound,llm,…`) | Default-deny gate. Without it `NotificationEmailNotifier` and the digest command silently skip sending. |
| `MAILER_FROM` | `no-reply@<your-domain>` (e.g. `no-reply@worktide.wappler.systems`) | From-address on every notification/digest mail. |
| `MAILER_FROM_NAME` | e.g. `Worktide` (or `BRAND_NAME`) | Display name; empty → falls back to `BRAND_NAME` → "Worktide". |
| `PORTAL_BASE_URL` | real portal origin, e.g. `https://portal.wappler.systems` | Prefixed onto the stored relative link for **portal** recipients (`…/tickets/<id>`). Wrong value → dead links in customer mail. |
| `SPA_BASE_URL` | real staff origin, e.g. `https://app.wappler.systems` | Same, for **staff** recipients. |
| `DEFAULT_URI` | backend public URL | Used for the logo URL rendered into emails (no request context in the worker). |

**Deliverability:** the `MAILER_FROM` domain must publish **SPF + DKIM** (and ideally
DMARC) for the chosen provider, or mail lands in spam / is rejected.

## 2. Infrastructure (must be running)

- [ ] **`worker` container** — `compose.prod.yaml` service `worker` runs
  `messenger:consume async ai_agents search …`. `SendEmailMessage` routes to the
  `async` transport (`config/packages/messenger.yaml`), so **without this container
  every notification/digest email queues in `messenger_messages` and never sends.**
- [ ] **`scheduler` container** — `compose.prod.yaml` service `scheduler` runs
  supercronic over `frankenphp/crontab`, which now includes the digest lines
  (`app:notifications:send-digest --frequency=daily` @ 07:00,
  `--frequency=weekly` Mon @ 07:05). Without it, **daily/weekly digests never fire**
  (instant email is unaffected — it's sent inline via the worker).

> ⚠️ The prod topology note lists api/hub/web/portal/mysql — it does **not**
> mention `worker`/`scheduler`. Confirm the Coolify stack actually launches both
> `compose.prod.yaml` services, not just the five. This is the most likely
> go-live gap.

## 3. Deploy / migration

- [ ] Migration `Version20260710170835` (adds `user_preferences.notification_preferences`
  + `last_notification_digest_at`) is applied — via the app container's auto-migrate
  on boot, or `php bin/console doctrine:migrations:migrate --no-interaction`.

## 4. Verify in prod (after the above)

1. Trigger a real notification (staff reply on a real customer's ticket, or assign a task).
2. Confirm a `SendEmailMessage` row appears in `messenger_messages` and the `worker`
   drains it (worker logs / `messenger:stats`).
3. Confirm the recipient (a **real** address — seeded `*.example` contacts are
   undeliverable by design) receives the mail with a working deep-link.
4. Manually smoke-test the digest: `php bin/console app:notifications:send-digest --frequency=daily`.
5. Portal `/einstellungen` loads and saves — customers can opt down to digest / off.

## 5. Defaults & kill switch

- New users default to **email on / instant / all types** (in-app bell is always on
  and independent of email). Customers self-manage at `/einstellungen`.
- Panic off: remove `email_outbound` from `EGRESS_ALLOW` — instantly stops all
  outgoing notification mail without a deploy (notifications still show in-app).
