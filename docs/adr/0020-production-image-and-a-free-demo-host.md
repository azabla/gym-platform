# ADR 0020: A production image, and a free demo host

- **Status:** Accepted
- **Date:** 2026-10-30

## Context

There was only a development Docker image (ADR 0002), which mounts the code and needs separate containers for nginx, the queue worker and the scheduler. The gym has no server yet, but the members app (`ads-tres/Volt-Fitness`) needs the API somewhere it can reach to be built against and shown.

## Decision

### One production image

- **A `prod` stage** in `docker/php/Dockerfile`, now the default target; development keeps asking for `dev`.
- **What's inside:**
  - the code with `composer install --no-dev`, so Filament's assets are published at build time;
  - nginx on port 8080, PHP-FPM, the queue worker and the scheduler, all run by supervisor.
- **One container is the whole app,** because free and small hosts give one.
- **At start** it:
  1. caches config, routes, views and events from the environment the host gives;
  2. runs the migrations;
  3. seeds the demo gym when `SEED_DEMO=true` (the seeders are safe to run again);
  4. starts supervisor.
- **Logs** go to the container's output (`LOG_CHANNEL=stderr`), so the host shows them, including the SMS sent in log mode.
- **`TRUSTED_PROXIES`** names the host's load balancer (`*` for any), so links and redirects use https and rate limits see the client's IP. It is empty in development.
- **CI builds the image and starts it** against Postgres. It checks that the app answers `/up`, serves the panel's sign-in page and signs the demo owner in.

### A free demo on Render, with Neon's Postgres

- **`render.yaml`** describes one free Render web service from the image.
- **The database is Neon's free Postgres,** given as `DB_URL`.
- **Sessions, cache and the queue use the database,** so no Redis is needed.
- **SMS and pushes go to the log;** Chapa uses each gym's test key, as connected in the API.
- **It is a demo, not production:**
  - the free service sleeps after about 15 minutes idle, and the next request wakes it in up to a minute;
  - the scheduler only runs while it's awake;
  - files stay on the container's disk.

### Deferred, for the real server

- Sentry for errors, nightly `pg_dump` to S3-type storage, Redis.
- A separate worker container, and S3 for files.
- The real AfroMessage, Chapa and FCM credentials.

## Consequences

- The members app can point its staging build at the Render address.
- The same image runs on a real server later, with Redis and more workers added by environment, not by a new image.
- The demo's data is shared by everyone who uses it, so nothing private goes there.
