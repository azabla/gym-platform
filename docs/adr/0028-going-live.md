# ADR 0028: Going live

- **Status:** Accepted
- **Date:** 2026-11-15

## Context

Staging (ADR 0020) ran everything on the `log` drivers: text messages and pushes were written to the log, and nothing told anyone when the app broke. The real accounts were all built for, but never connected:
- AfroMessage for SMS (ADR 0010);
- Firebase for push (ADR 0012);
- each gym's Chapa key (ADR 0009).

Three things stood between staging and a gym relying on it:
- **Firebase's key.** It was read from a file (`FCM_CREDENTIALS`), and Render's free plan has no disk to put one on.
- **Errors.** Errors went only to the log, which nobody watches.
- **Backups.** Neon's free plan keeps only a short history, so the database had no copy of its own.

No accounts exist yet, so this piece builds and documents everything, and staging stays on the `log` drivers until they do.

## Decision

### Firebase's key from an environment variable

- **`FCM_CREDENTIALS_JSON`** holds the service account's JSON key, base64-encoded, and is read before `FCM_CREDENTIALS`, the path to the file, which development keeps using.
- **A key that isn't JSON says so:** pushes fail with `PushNotSent` naming the variable to fix.

### Errors go to Sentry

- **Package:** `sentry/sentry-laravel`, the one new package.
- **Off by default.** It sends only when `SENTRY_LARAVEL_DSN` is set, so development, tests and CI send nothing.
- **Little personal data.** `send_default_pii` stays off: no names, phone numbers, IP addresses or request bodies. An error carries only the signed-in user's id and the gym's slug (`gym` tag, `none` outside a gym), set in `bootstrap/app.php` just before it is sent.
- **Expected answers aren't errors.** Validation errors, 404s and the domain refusals (`PaymentRefused`, `MembershipRefused`, `AnnouncementRefused`, `StaffRefused`, which become validation errors) are not sent. Laravel already leaves them out, and a test holds that.
- **No performance tracing.** Errors only, which fits Sentry's free plan.

### Nightly backups to a private bucket

- **What runs.** `php artisan db:backup` runs `pg_dump` in its custom format and uploads the dump to the `backups` disk as `YYYY-MM-DD.dump` (the UTC date). It then deletes dumps older than 30 days, and leaves other files alone.
- **Where dumps go.** The `backups` disk is the same R2 account as the `s3` disk, but its own bucket, `BACKUP_AWS_BUCKET`. The `s3` disk's bucket is public through `r2.dev`, and a dump holds every member's phone number, so the command refuses to run without a bucket of its own.
- **When.** It is scheduled at 23:00 UTC, 02:00 in Addis Ababa, when no gym is open, on the deployed app only (`APP_ENV=production`).
- **Failing loudly.** A failed dump or upload throws, so it reaches the log and Sentry. The day's dump is missing, and the old ones are kept.
- **The client.** The Render image installs the PostgreSQL 17 client from PostgreSQL's apt repository. Debian's own is version 15, and `pg_dump` refuses to dump a newer server.
- **Restoring:** `pg_restore` with the dump, described in `docs/development-setup.md`.

### Declared, not switched on

- **Asked for once.** `render.yaml` asks once for these, never committed:
  - AfroMessage's token, identifier and sender name;
  - Firebase's project and key;
  - Sentry's DSN;
  - the backups bucket.
- **Still on log.** `SMS_DRIVER` and `PUSH_DRIVER` stay `log`.
- **Going live** is filling those in and switching the drivers, as `docs/development-setup.md` describes.
- **Chapa:** each gym still connects its own live key (ADR 0009).

### Deferred

- **The real server** (ADR 0020):
  - an always-on instance, so the scheduler never sleeps;
  - separate worker and scheduler processes;
  - Redis;
  - a domain.
- **Restore drills:** restoring a dump to a scratch database on a schedule, to prove backups work.
- **Uploads:** backing up uploaded files, of which there are none yet.
- **Real mail,** which nothing sends to members yet.

## Consequences

- Each account can be connected on its own, from Render's dashboard, without a code change.
- Errors from the API, the panel, the queue and the scheduler reach Sentry once its DSN is set, with only enough context to find the gym and the user.
- **Backups and Render's free plan.** The free plan sleeps after 15 minutes without visits, and a sleeping scheduler misses its runs rather than catching up. On the free plan a night's backup is taken only if the service is awake at 23:00. On an always-on instance it runs every night.
- **Storage.** Thirty daily dumps sit in R2; at staging's size, well within R2's free storage.
