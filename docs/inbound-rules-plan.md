# Inbound rules — roadmap

## Where we are (shipped)

A Thunderbird-style **mute rule** engine for inbound messages:

- `InboundMuteRule` (workspace-scoped): `combinator` (AND/OR) + `conditions[]`
  (`{field, operator, value}`).
  - Fields: `sender_email`, `subject`, `body`, `channel_adapter`.
  - Operators: `contains`, `not_contains`, `equals`, `not_equals`, `starts_with`,
    `ends_with`, `regex` (case-insensitive; regex is PCRE `…/i`).
- Evaluated at ingest by `InboundMuteMatcher` in `InboundEventProcessor` (right
  after threading). A match flags the conversation `mutedAt` and short-circuits
  auto-reply / AI suggestion / n8n dispatch.
- One action only, implicit: **hide** (`mutedAt`) — kept + searchable, out of the
  default inbox, in the "Ignoriert"/"Muted" view. Reversible.
- Managed by: one-click `POST /conversations/{id}/mute-sender` (SPA button, staff
  JWT) and token-authed CRUD `/v1/automation/mute-rules` (n8n). Worktide is the
  single source of truth; n8n manages rules via the API.

This is the analogue of Thunderbird's **conditions** panel + a single "move to
folder" action.

## Roadmap — generalise into a full inbound-rule engine

Modelled on Thunderbird's full filter dialog (conditions → **actions**, triggers).

1. **Multiple action types** (the big one). Promote `InboundMuteRule` →
   `InboundRule` with an ordered `actions[]`, each a typed action:
   - `hide` (today's mute) · `add_tag` · `set_status` · `assign_user` ·
     `set_priority` · later `forward` / `notify`.
   - Reuses the same action vocabulary the n8n apply-endpoint already exposes
     (status/tags/note/muted) so internal rules and n8n stay consistent.
2. **Trigger timing / ordering.** Today: fixed at ingest, before AI/automation.
   Thunderbird lets you choose "before spam detection" etc. Expose where a rule
   runs relative to AI-suggest / n8n dispatch (e.g. `stopProcessing` flag so a
   matched rule can end the pipeline, or let processing continue).
3. **Back-fill at scale.** `mute-sender` currently scans up to
   `BACKFILL_LIMIT = 5000` conversations in PHP. For large mailboxes (the
   "replace an email client" goal) make it batched + queued (a Messenger job),
   and push down `contains`/`equals` on `sender`/`subject` to SQL where possible.
4. **SPA rule management.** Today: only the one-click mute form + the "Ignoriert"
   filter. Add a full rule list + builder (add/remove condition rows, AND/OR
   toggle, field/operator dropdowns, action list) under Settings — the
   Thunderbird "Filter bearbeiten" UI.
5. **Nested condition groups.** Thunderbird is flat AND/OR; genuine nested
   boolean trees / cross-message / external-data logic stay in **n8n**
   (process-then-hide via the apply endpoint) — we deliberately do not rebuild a
   full flow engine internally.

## Boundary with n8n (unchanged)

- **Internal rules** = declarative, per-message, fast, deterministic, n8n-free.
  Thunderbird-grade (fields + operators incl. negation/regex + AND/OR) covers the
  large majority (e.g. sender AND subject → mute only 2FA, not all of a sender).
- **n8n** = procedural / multi-step / stateful / external-data / OR-trees with
  exclusions / "process then hide". Reached via the automation apply endpoint
  (`muted`, status, tags, note).
