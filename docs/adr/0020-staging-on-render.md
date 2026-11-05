# ADR 0020: Staging on Render

- **Status:** Accepted
- **Date:** 2026-11-05

## Context

The platform only ran on developers' machines, so no gym owner could try it. We want a staging copy on the internet now, at no cost, and a real server later. The app is more than a web server: queued notifications and announcements need a queue worker, and the schedule in `routes/console.php` (payment link checks, reminders, due announcements) needs the scheduler. Files go to S3, and in development sessions, cache and the queue go to Redis.

## Decision

### Render's free plan, one container

- **Where:** Render, Frankfurt, the closest of its regions to Ethiopia. Everything is declared in `render.yaml`, a Render Blueprint, and deploys from the `deployment` branch on every push.
- **Services:** one free web service and free Postgres 17 (`gym-db`).
- **No Redis on staging:** Render allows one free Key Value per workspace, and ours is taken. Sessions, cache (including the scheduler's `withoutOverlapping` locks) and the queue use Laravel's `database` drivers, whose tables the migrations already create. The app never talks to Redis directly, so nothing else changes.
- **One container runs three processes** (`docker/render/`): Apache with mod_php serves the app, `queue:work` and `schedule:work` run beside it, and supervisord restarts any that stop. The free plan has no background workers or cron jobs, so they can't be separate services.
- **The image** builds the Vite assets, installs the production Composer dependencies and publishes Filament's assets at build time. Apache runs few workers, because the free plan's 512 MB is shared with the worker and the scheduler.
- **On every start** `start.sh` caches config, routes, views and events (the environment exists only then), runs `migrate --force` (the free plan has no pre-deploy step), and starts supervisord. `APP_URL` defaults to the `onrender.com` address Render gives the service.

### Configuration

- **Secrets are never committed.** `APP_KEY` and the R2 keys are `sync: false` in `render.yaml`, entered once in Render's dashboard. The database address comes from the database service itself.
- **Trusted proxies:** Render serves the app over https through its own proxy. `config/trustedproxy.php` reads `TRUSTED_PROXIES`, set to `*` on Render, so links and Filament's assets use https and the real host. Left empty, as in development, forwarded headers are ignored.
- **Files:** Cloudflare R2 through the existing `s3` disk (free tier, S3 compatible), so uploads survive deploys. Render's own disk is wiped on every deploy.
- **Messages:** mail, SMS and push use the `log` drivers, so they appear in Render's logs (`LOG_CHANNEL=stderr`) until the real accounts are connected.
- **The first gym:** the free plan has no shell, so `gyms:create` runs from a developer's machine against the database's external URL (see `docs/development-setup.md`).

### Deferred

- The real server: separate web, worker and scheduler processes, Redis, backups and a domain.
- Real SMS, push and mail on staging.

## Consequences

- Anyone with the link can try the panel and the API, and staging costs nothing.
- The queue worker polls the `jobs` table every few seconds; at staging's load that is negligible, but the real server should use Redis as development does.
- The free web service sleeps after 15 minutes without visits and takes about a minute to wake. While it sleeps, the scheduler doesn't run: reminders, payment link checks and scheduled announcements catch up on the next visit, since each run looks at everything due.
- Render's free Postgres expires 30 days after it is created; staging data is disposable, and the database is recreated from the Blueprint.
- Migrations run on start, so a failing migration stops the deploy; Render keeps the previous version running.
- `docker/render/` only suits a single container. The real server will need its own setup, though the image's build steps carry over.
