# Welcome to Worktide

## How We Use Claude

Based on Sven Wappler's usage over the last 30 days:

Work Type Breakdown:
  Plan Design     ████████████░░░░░░░░  67%
  Build Feature   ███████░░░░░░░░░░░░░░  33%

Top Skills & Commands:
  /clear          ████████████████████  2x/month

Top MCP Servers:
  chrome-devtools ████████████████████  7 calls

## Your Setup Checklist

### Codebases
- [ ] worktide — https://github.com/worktide-io/worktide (Symfony 8.1 / API Platform 4 backend, API-only)
- [ ] worktide-web — the Refine + React + Vite SPA frontend (sibling repo, lives next to worktide)

### MCP Servers to Activate
- [ ] chrome-devtools — drive a real Chrome to inspect, screenshot, and debug the SPA (console errors, network, performance). Add the `chrome-devtools` MCP server to your Claude Code config; no external account needed.

### Skills to Know About
- [ ] /clear — reset the conversation context between unrelated tasks so Claude starts fresh. Use it when you switch from one feature/investigation to another.

## Team Tips

- **Two repos, side by side.** `worktide` is the API-only Symfony 8.1 / API Platform 4 backend; `worktide-web` is the Refine + React + Vite SPA. Clone them as siblings — the SPA's Vite dev server proxies `/v1/*` to the backend, so no CORS headaches in dev.
- **Everything runs in DDEV.** Backend at `https://api.worktide.ddev.site` (`/v1`), SPA at `https://worktide-web.ddev.site`. Vite runs as a supervisord daemon inside DDEV; tail it with `ddev logs -f`.
- **API-first — the OpenAPI docs are your map.** Every one of the ~87 entities is a REST resource. Browse them at `https://api.worktide.ddev.site/v1/docs` before writing a new endpoint; chances are it's already there.
- **"Backend done, SPA open" is the common shape.** Lots of features (Status-Updates, Workflow-Editor, Public Forms, Custom Dashboards) have a complete backend and only need frontend work. `ROADMAP.md` marks exactly what's shipped vs. what's still SPA-only.
- **Async work goes through Messenger.** Webhooks, entity-sync, and schedules run on the worker: `ddev exec php bin/console messenger:consume async -vv`. If something "doesn't fire," check the worker is running.
- **Regenerate the API client after backend changes.** In `worktide-web`: `ddev exec pnpm gen:api` (kubb) pulls fresh types from the OpenAPI spec.
- **Debug the SPA with the chrome-devtools MCP** — console errors, network, screenshots. Use the singleton flag and a cachebust query param.

## Get Started

1. **Clone both repos as siblings:**
   ```bash
   git clone https://github.com/worktide-io/worktide.git
   git clone https://github.com/worktide-io/worktide-web.git   # sibling of worktide
   ```
2. **Bring up the backend:**
   ```bash
   cd worktide
   ddev start
   ddev composer install
   ddev exec php bin/console doctrine:migrations:migrate --no-interaction
   ddev exec php bin/console doctrine:fixtures:load --no-interaction
   ```
3. **Bring up the SPA:**
   ```bash
   cd ../worktide-web
   ddev start        # serves https://worktide-web.ddev.site
   ```
4. **Log in.** Reset a demo password and sign in at `https://worktide-web.ddev.site`:
   ```bash
   cd ../worktide
   ddev exec php bin/console app:user:reset-password admin@example.com demo
   ```
5. **Poke the API** to confirm everything's wired up — open `https://api.worktide.ddev.site/v1/docs`, or:
   ```bash
   curl -s https://api.worktide.ddev.site/v1/projects | jq
   ```
6. **Run the worker** in a spare terminal if you're touching webhooks, sync, or schedules:
   ```bash
   ddev exec php bin/console messenger:consume async -vv
   ```

You're set. Pick something from `ROADMAP.md` — the SPA-only items are the fastest way to ship something visible on day one.
