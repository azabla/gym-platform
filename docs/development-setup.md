# Development Setup

Everything runs in Docker. You need only **Docker** (Docker Desktop on macOS/Windows, Docker Engine on Linux), **Git** and **make**. You do not need PHP, Composer or PostgreSQL on your machine.

Windows: install WSL2 with Ubuntu, and clone the project **inside** the WSL filesystem (`~/code/...`), not under `/mnt/c`. File access across that boundary is very slow.

## Services

| Service | What it does | From your browser/host |
|---|---|---|
| `app` | PHP-FPM running Laravel | (through nginx) |
| `nginx` | Web server | http://localhost:8080 |
| `queue` | Runs queued jobs | – |
| `scheduler` | Runs scheduled tasks every minute | – |
| `postgres` | Databases `gym` (dev) and `gym_testing` (tests) | `localhost:5432` |
| `redis` | Cache, queues, sessions, locks | `localhost:6379` |
| `mailpit` | Catches all outgoing email | http://localhost:8025 |
| `seaweedfs` | S3-compatible file storage | http://localhost:8333 |

Inside Docker, containers reach each other by **service name** (`postgres`, `redis`, ...), never `localhost`. Inside a container, `localhost` means that container itself.

All ports are bound to `127.0.0.1`, so nobody else on your Wi-Fi can reach your database.

## First-time setup (from an empty folder)

The current repository already contains the Laravel scaffold. Use [Cloning an existing checkout](#cloning-an-existing-checkout) below; this section records how the initial scaffold was created.

These steps create the Laravel project. They are done once. A teammate cloning the finished repository follows "Cloning an existing checkout" below instead.

**1. Add the Docker files.** Put `compose.yaml`, `Makefile`, `.dockerignore`, `phpstan.neon` and the `docker/` folder in the project root. Then initialise Git:

```bash
git init
```

**2. Build the PHP image.**

```bash
make build
```

**3. Create the Laravel project inside the container.** Composer refuses to create a project in a non-empty folder, so we create it in `/tmp` and copy it in:

```bash
docker compose run --rm --no-deps app sh -c \
  "composer create-project laravel/laravel:^13.0 /tmp/app && cp -a /tmp/app/. /var/www/html/"
rm -f database/database.sqlite
```

`--no-deps` stops Compose from starting Postgres/Redis for this one-off command. `--rm` deletes the temporary container afterwards.

**4. Configure `.env`.** Replace the matching lines in `.env` with the block below. Make the same changes in `.env.example` (without real secrets), because that file is what teammates copy.

```dotenv
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=gym
DB_USERNAME=gym
DB_PASSWORD=secret

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=gym-local-key
AWS_SECRET_ACCESS_KEY=gym-local-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=gym-local
AWS_ENDPOINT=http://seaweedfs:8333
AWS_URL=http://localhost:8333/gym-local
AWS_USE_PATH_STYLE_ENDPOINT=true
```

`AWS_ENDPOINT` is how PHP (inside Docker) reaches storage. `AWS_URL` is how your browser reaches it. They differ because of the service-name rule above.

**5. Start everything.**

```bash
make up
make ps        # postgres and redis should show "healthy"
make bucket    # creates the S3 bucket, once
```

**6. Install packages.**

```bash
# S3 driver for Laravel's Storage
make composer c='require league/flysystem-aws-s3-v3 "^3.0"'

# Pest instead of PHPUnit
make composer c="remove phpunit/phpunit"
make composer c="require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies"
docker compose exec app ./vendor/bin/pest --init

# Static analysis
make composer c="require larastan/larastan --dev"
```

Pint (code style) already ships with Laravel.

**7. Point tests at PostgreSQL.** In `phpunit.xml`, set (or replace the sqlite lines with):

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="gym_testing"/>
```

We test against PostgreSQL, not SQLite, because production is PostgreSQL. Constraints, row-level security and JSONB behave differently in SQLite, so SQLite tests can pass while production fails.

**8. Create the domain folders and add the Money code** from Lesson 1:

```bash
mkdir -p app/Domains/{Shared,Identity,Organizations,Members,Memberships,Billing,Attendance,Notifications,Audit}
mkdir -p tests/Unit/Domains tests/Feature/Domains
```

Copy `app/Domains/Shared/Money/*`, `tests/Unit/Domains/Shared/Money/MoneyTest.php` and `tests/Unit/ArchitectureTest.php` into place. Empty folders are not tracked by Git; they appear in commits as soon as they hold a file.

**9. Migrate and verify.** Follow "Verifying the environment" below.

**10. First commit.**

```bash
make check
git add -A
git commit -m "Scaffold Laravel app with Docker development environment"
```

## Cloning an existing checkout

```bash
git clone <repo> gym-platform && cd gym-platform
cp .env.example .env
make build
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app php artisan key:generate --no-interaction
make up
make bucket
make artisan c=migrate
make test
```

## Verifying the environment

Each check proves one service works end to end.

```bash
curl -i http://localhost:8080/up          # nginx + PHP-FPM + Laravel: expect HTTP 200
make artisan c=migrate                    # PostgreSQL
make test                                 # Pest + gym_testing database
make analyse                              # Larastan
make lint                                 # Pint
```

Then open a shell with `make shell`, start `php artisan tinker` and run:

```php
Cache::put('ping', 'pong'); Cache::get('ping');           // Redis → "pong"
Storage::put('hello.txt', 'Selam'); Storage::get('hello.txt');   // SeaweedFS → "Selam"
Mail::raw('Selam from the gym platform', fn ($m) => $m->to('owner@example.com'));
dispatch(fn () => logger('The queue works'));
```

The email should appear at http://localhost:8025. For the queue, run `docker compose logs queue` and check `storage/logs/laravel.log` for "The queue works".

## Daily workflow

```bash
make up          # start of day
make test        # often
make workers     # after changing code that jobs or scheduled tasks run
make down        # end of day (data is kept in volumes)
```

The queue worker loads your code once and keeps it in memory. That is why it needs `make workers` after code changes, while web requests pick changes up immediately.

The Redis queue reserves jobs for 120 seconds, longer than the worker's 90-second timeout. This gives a timed-out worker time to stop before another worker retries the job.

Use the Docker/Make commands above for this setup. The scaffold's `composer run dev` and `composer run setup` commands assume PHP and Node tooling on the host and are not needed to run the default welcome page through Docker.

## Trying the API

After pulling changes that add packages or migrations, install and rebuild the database with the demo data:

```bash
make composer c=install
make fresh
```

`make fresh` seeds Demo Gym (`demo-gym`) with two branches, three members, five plans (one valid only at Bole Branch), two memberships, two payments and three staff, all with the password `password`. Staff sign in with their phone; the API also takes the email:

- `0911 00 00 01` (`owner@demo-gym.test`), the Owner, who works at both branches;
- `0911 00 00 02` (`frontdesk@demo-gym.test`), a Receptionist limited to Main Branch;
- `0911 00 00 03` (`accounts@demo-gym.test`), an Accountant, who works at both branches.

```bash
# Sign in and keep the token
TOKEN=$(curl -s http://localhost:8080/api/v1/auth/tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"phone":"0911 00 00 01","password":"password","device_name":"curl"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')

# Who am I, and which gyms do I work at?
curl -s http://localhost:8080/api/v1/me \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# The branches I work at in Demo Gym
curl -s http://localhost:8080/api/v1/organizations/demo-gym/branches \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Demo Gym's members (add ?search=abebe or ?search=0911 to filter)
curl -s http://localhost:8080/api/v1/organizations/demo-gym/members \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Register a member at Main Branch (id 1 after make fresh)
curl -s http://localhost:8080/api/v1/organizations/demo-gym/members \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"full_name":"Selam Tesfaye","phone":"0944 56 78 90","home_branch_id":1}'

# The plans on sale: 3 Months, Bole Monthly, Day Pass, Monthly and Yearly
curl -s http://localhost:8080/api/v1/organizations/demo-gym/plans \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Sell Monthly (plan 2 after make fresh) to Hana Girma (member 3); it starts today
curl -s http://localhost:8080/api/v1/organizations/demo-gym/members/3/memberships \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"plan_id":2}'

# Cancel that membership (membership 3 after make fresh; owners and managers only)
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/3/cancellation \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"reason":"Sold the wrong plan."}'

# Sell Hana Monthly again, 10% off for a student (owners and managers only); "500" takes 500 ETB off instead
curl -s http://localhost:8080/api/v1/organizations/demo-gym/members/3/memberships \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"plan_id":2,"discount":"10%","discount_reason":"Student"}'

# Freeze Abebe's membership (membership 1 after make fresh) from today for a week;
# its end date moves a week later. Monthly memberships can be frozen for 7 days.
END=$(date -d '+6 days' +%F 2>/dev/null || date -v+6d +%F)
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/1/freezes \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d "{\"ends_on\":\"$END\",\"reason\":\"Travelling\"}"

# Abebe is back early: end that freeze (freeze 1 after make fresh)
curl -s -X POST http://localhost:8080/api/v1/organizations/demo-gym/memberships/1/freezes/1/end \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# A plan valid only at Bole Branch (branch 2 after make fresh), 30 days, no freezing
curl -s http://localhost:8080/api/v1/organizations/demo-gym/plans \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Bole 30 Days","price":"1,800","duration_count":30,"duration_unit":"day","branch_ids":[2]}'

# Hana pays 1,000 of her 2,250 in cash at Main Branch (membership 4, the one sold at 10% off).
# The owner works at both branches, so says which one; staff at one branch can leave it out.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/4/payments \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"amount":"1,000","method":"cash","branch_id":1}'

# She pays the other 1,250 by Telebirr, with the transaction number from her SMS
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/4/payments \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"amount":"1,250","method":"telebirr","reference":"DK56XYZ78A","branch_id":1}'

# Today's payments at Main Branch, with the totals for each method in meta.totals
curl -s "http://localhost:8080/api/v1/organizations/demo-gym/payments?from=$(date +%F)&to=$(date +%F)&branch_id=1" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# The receipt for Hana's cash payment (payment 3 after make fresh)
curl -s http://localhost:8080/api/v1/organizations/demo-gym/payments/3 \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# The Telebirr payment was meant for another member: void it (payment 4; owners, managers and
# accountants only). It stays on record but no longer counts, so Hana owes 1,250 again.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/payments/4/void \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"reason":"Recorded for the wrong member."}'

# Abebe moves away: cancel his membership (membership 1), then give 1,000 ETB back in cash
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/1/cancellation \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"reason":"Moved to another city."}'
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/1/refunds \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"amount":"1,000","method":"cash","branch_id":1}'

# The gym at a glance today: money (owners, managers and accountants only), check-ins,
# members and the memberships ending this week. Add ?from=2026-10-01&to=2026-10-31 for a month.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/dashboard \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Main Branch's opening hours (seeded Monday to Saturday, 06:00 to 21:00); outside them
# a check-in is turned away with OUTSIDE_HOURS. PUT {"hours":[]} makes it always open.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/branches/1/opening-hours \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Tell members the gym is closed on Sunday, now; add "send_at":"2026-11-01T07:00:00+03:00" to schedule it
# and "branch_id":1 for one branch. The answer says how many members it reached.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/announcements \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Closed on Sunday","body":"The gym is closed this Sunday for maintenance."}'

# The gym's staff (owners and managers). Add a trainer at Main Branch: they get an SMS with a link
# to set their password, in the log in development. Leave out branch_ids for all branches.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/staff \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -s http://localhost:8080/api/v1/organizations/demo-gym/staff \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Selam Tadesse","phone":"0944 55 66 77","role":"trainer","branch_ids":[1]}'

# Archive them (staff 4 after make fresh): they lose the gym at once. POST .../staff/4/restore brings them back.
curl -s -X DELETE http://localhost:8080/api/v1/organizations/demo-gym/staff/4 \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Sign out (revokes the token)
curl -s -X DELETE http://localhost:8080/api/v1/auth/tokens/current \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

The members list shows each member's membership at a glance and what they still owe: Abebe is active and paid up, Tigist's membership has expired and she still owes 1,000 ETB, and Hana has none until you sell her one.

Sign in as `frontdesk@demo-gym.test` instead and the branch list shrinks to Main Branch, but the members list still shows every member of the gym. The receptionist:

- can sell memberships, and freeze them within their plan's freeze days;
- can take payments, dated today, at Main Branch;
- can create, check and cancel payment links;
- can't cancel memberships, give discounts or go past the freeze days;
- can't refund, void, date a payment in the past or connect Chapa.

Sign in as `accounts@demo-gym.test` to work as the accountant: they see payments, take them, refund and void, but can't sell memberships or edit members.

### Paying online with Chapa (test mode)

Payment links need the gym's Chapa account. Use a test key, which takes no real money:

1. Create an account on Chapa's dashboard and copy its test secret key. It starts with `CHASECK_TEST-`; the public key (`CHAPUBK…`) is refused.
2. Sign in as the owner again (the first command above), connect the key, and create a link for the 1,000 ETB Tigist still owes:

```bash
# Connect Demo Gym's Chapa account (owners only). Responses show only the key's last 4 characters.
curl -s -X PUT http://localhost:8080/api/v1/organizations/demo-gym/chapa-account \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"secret_key":"CHASECK_TEST-paste-your-test-key-here"}'

# A link for what Tigist still owes (membership 2 after make fresh), at Main Branch.
# The response has the checkout_url to send her.
curl -s http://localhost:8080/api/v1/organizations/demo-gym/memberships/2/payment-links \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"branch_id":1}'
```

3. Open the `checkout_url` in a browser and pay with one of the test payment methods in Chapa's docs.
4. Chapa can't reach `localhost`, so its callback never arrives on your machine. Ask Chapa yourself, or wait for the `scheduler` container, which checks open links every 10 minutes:

```bash
# Ask Chapa about link 1 now. Once it's paid, it shows "status":"paid" and its receipt number.
curl -s -X POST http://localhost:8080/api/v1/organizations/demo-gym/payment-links/1/check \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# The payment: method chapa, Chapa's reference as its transaction number, and no staff member
curl -s "http://localhost:8080/api/v1/organizations/demo-gym/payments?method=chapa" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

Tigist now owes nothing. `GET payment-links` lists the gym's links, and `?status=refund_needed` shows the ones paid when nothing was due, which the gym refunds from its Chapa dashboard. `POST payment-links/{id}/cancel` cancels an open link, and `make artisan c=billing:check-payment-links` runs the scheduled check by hand.

### The members app

Members sign in with a code sent to their phone. In development `SMS_DRIVER=log`, so the queue worker writes the SMS to the log instead of sending it. Run `make workers` first, so the worker has the latest code.

```bash
# Ask for a code for Tigist (0922 34 56 78). Every number gets the same answer.
curl -s http://localhost:8080/api/v1/auth/sms-codes \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"phone":"0922 34 56 78"}'

# Read the code from the log
grep 'SMS to +251922345678' storage/logs/laravel.log | tail -1

# Sign in with that code (in place of 123456) and keep the token
MEMBER_TOKEN=$(curl -s http://localhost:8080/api/v1/auth/sms-tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"phone":"0922 34 56 78","code":"123456","device_name":"curl"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')

# Tigist's gyms: Demo Gym, with what she still owes there
curl -s http://localhost:8080/api/v1/me/gyms \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# Her home screen in one call: membership, what she owes, recent visits, unread messages
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/dashboard \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# Her memberships and her receipts at Demo Gym
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/memberships \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/payments \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# Pay what she owes on membership 2. This needs the gym's Chapa test key (above),
# and gives the open link if there is one. The response has the checkout_url.
curl -s -X POST http://localhost:8080/api/v1/me/gyms/demo-gym/memberships/2/payment-link \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"
```

After paying on Chapa's page, `POST me/gyms/demo-gym/payment-links/{id}/check` records the payment, as the app does when the member comes back from Chapa.

### Checking in with a QR code

The members app makes Tigist's QR code from a secret the server gives it once. Here PHP plays the phone. The contract for the app is `docs/api/members-app.md`.

```bash
# Tigist issues her phone's QR credential for Demo Gym. Keep the credential_id and secret.
curl -s -X POST http://localhost:8080/api/v1/me/gyms/demo-gym/access-credential \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# Make the code her phone shows right now: in another terminal, run `make shell`, then
#   php artisan tinker --execute 'echo App\Domains\Attendance\Credentials\Gq1::payload(base64_decode("SECRET"), "CREDENTIAL_ID", App\Domains\Attendance\Credentials\Gq1::stepAt(time()));'
# and paste what it prints here. A code works for about a minute, so scan it soon.
PAYLOAD='GQ1:…'

# The receptionist signs in and scans it at Main Branch, their only branch, so no branch_id
DESK_TOKEN=$(curl -s http://localhost:8080/api/v1/auth/tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"frontdesk@demo-gym.test","password":"password","device_name":"door"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')
curl -s http://localhost:8080/api/v1/organizations/demo-gym/check-ins/scan \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $DESK_TOKEN" \
  -d "{\"payload\":\"$PAYLOAD\"}"

# Scan the same code again: now "reason_code":"CODE_ALREADY_USED"
curl -s http://localhost:8080/api/v1/organizations/demo-gym/check-ins/scan \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $DESK_TOKEN" \
  -d "{\"payload\":\"$PAYLOAD\"}"

# Today's check-ins at the gym, and Tigist's visits in the app
curl -s "http://localhost:8080/api/v1/organizations/demo-gym/check-ins?on=$(date +%F)" \
  -H 'Accept: application/json' -H "Authorization: Bearer $DESK_TOKEN"
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/check-ins \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"
```

Tigist's membership in the demo data has expired, so the first scan answers `"decision":"deny"` with `MEMBERSHIP_EXPIRED`, and her membership and what she owes. Sell her a new one (above), and a fresh code lets her in; another within 5 minutes is denied with `ALREADY_CHECKED_IN`. `POST organizations/demo-gym/members/{id}/check-ins` checks a member in at the desk instead.

### Buying a membership in the app

This needs the gym's Chapa test key (see "Paying online with Chapa").

```bash
# The plans Tigist can buy, with the dates each would run
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/plans \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# Start buying Monthly (plan 2 after make fresh). The answer has the checkout_url to open.
curl -s -X POST http://localhost:8080/api/v1/me/gyms/demo-gym/plans/2/purchase \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"

# After paying on Chapa's test page, check it (link id from the answer above)
curl -s -X POST http://localhost:8080/api/v1/me/gyms/demo-gym/payment-links/LINK_ID/check \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"
```

Once it's paid, the answer has the new `membership_id`, and her memberships list shows it, starting today because her last one has expired, with `sold_in_app: true`.

### Notifications

In development `PUSH_DRIVER=log` and `SMS_DRIVER=log`, so pushes and SMS land in `storage/logs/laravel.log`. Messages go through the queue, so run `make workers` first.

```bash
# Tigist's phone registers its push token (any text works with the log driver)
curl -s -X PUT http://localhost:8080/api/v1/me/push-token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $MEMBER_TOKEN" \
  -d '{"token":"tigists-test-token","locale":"en"}'

# Take a payment from her at the desk (see "Trying the API"), then read her inbox
curl -s http://localhost:8080/api/v1/me/gyms/demo-gym/notifications \
  -H 'Accept: application/json' -H "Authorization: Bearer $MEMBER_TOKEN"
grep 'Push to device' storage/logs/laravel.log | tail -1

# Turn off pushes about visits
curl -s -X PATCH http://localhost:8080/api/v1/me/gyms/demo-gym/notification-preferences \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $MEMBER_TOKEN" \
  -d '{"visits":false}'

# Expiry reminders run every hour from the scheduler; run them by hand
make artisan c=notifications:send-membership-reminders
```

A membership ending in 5, 3 or 1 days gets a reminder from 09:00 the gym's time, and an SMS at 3 days. Running the command again sends nothing more. To try it, set a membership's end date 3 days ahead in the database first.

Scheduled announcements go out from the scheduler every minute; `make artisan c=announcements:send-due` sends the due ones by hand.

Each part has an ADR:

- ADR 0004: how sign-in and gym selection work;
- ADR 0005: members and phone numbers;
- ADR 0006: plans and memberships;
- ADR 0007: freezing, branch-only plans and discounts;
- ADR 0008: billing and payments;
- ADR 0009: online payments with Chapa;
- ADR 0010: the members app, with SMS sign-in;
- ADR 0011: check-ins and rotating QR codes;
- ADR 0012: reminders and notifications;
- ADR 0013: buying a membership in the app;
- ADR 0014: dashboards;
- ADR 0015: announcements;
- ADR 0016: visit packs and opening hours;
- ADR 0017: the staff panel;
- ADR 0018: working the front desk in the staff panel.

## Trying the staff panel

`make composer c=install` also publishes the panel's assets. After `make fresh`, open http://localhost:8080/staff and sign in with one of the demo phones above (password `password`). You land on Demo Gym's dashboard: today's figures and the members to call. Change the period or the branch at the top.

- As the owner (`0911 00 00 01`), you see the money too, and every list: members, memberships, payments, check-ins and staff.
- As the receptionist (`0911 00 00 02`), the dashboard covers Main Branch only, and the money is hidden. The lists still show the whole gym, as in the API.
- As the accountant (`0911 00 00 03`), you see the money and the payments.

To work the front desk, as the receptionist:

1. On Members, choose **Register member**.
2. On the new member's page, choose **Sell membership**: Monthly, from today.
3. On the membership's page, choose **Take payment**: cash, the amount it suggests.
4. Back on the member's page, choose **Check in**. The member is let in.

Then sign in as the owner. On that membership's page, **Cancel membership**, then **Refund** the cash. On the refund's page, **Void** it if it was a mistake. Each action asks only for what the API would, and refuses what the API would refuse, in the same words.

Payment links stay in the app.

To add staff, as the owner, open **Staff** and choose **Add staff member**: name, phone, role and branches. The SMS goes through the queue (run `make workers` first) to `storage/logs/laravel.log`: `grep 'SMS to +251944556677' storage/logs/laravel.log | tail -1`. Open the link, choose a password, and sign in with their phone. On their page you can change the role or branches, **Archive** them (they lose the gym on their next click), **Restore** them, or **Send invite again** while it's pending. **Forgot your password?** on the sign-in page sends a reset link the same way. See ADR 0017 to ADR 0019.

### Starting a real gym

We create each gym, with its first branch and its owner; the owner adds the rest of the staff in the panel:

```bash
make shell
php artisan gyms:create --name="Bole Fitness" --slug=bole-fitness --owner-name="Meron Assefa" --owner-phone="0911 22 33 44"
```

It asks for anything left out. `--timezone` (default `Africa/Addis_Ababa`) and `--branch` (default `Main Branch`) are optional. The owner gets an SMS with a link, valid for 7 days, to set their password. See ADR 0019.

## Troubleshooting

**"port is already allocated".** Something on your machine already uses that port (often a local PostgreSQL). Set a different one in `.env`, for example `FORWARD_DB_PORT=5433`, then `make up`.

**"Permission denied" on `storage/` or `vendor/`.** The image was built with a different UID than yours. Run `make build` (the Makefile passes your UID), then `make up`.

**The `gym_testing` database does not exist.** Scripts in `docker/postgres/init/` run only when the data volume is created. If the volume already existed, create the database by hand:
`docker compose exec postgres createdb -U gym gym_testing`.
Alternatively, `docker compose down -v` deletes **all** volumes (every local database and file) and starts clean. Never run `down -v` casually.

**The queue or scheduler keeps restarting.** They crash until `vendor/` exists and `.env` is configured. Check with `docker compose logs queue`.
