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

## Troubleshooting

**"port is already allocated".** Something on your machine already uses that port (often a local PostgreSQL). Set a different one in `.env`, for example `FORWARD_DB_PORT=5433`, then `make up`.

**"Permission denied" on `storage/` or `vendor/`.** The image was built with a different UID than yours. Run `make build` (the Makefile passes your UID), then `make up`.

**The `gym_testing` database does not exist.** Scripts in `docker/postgres/init/` run only when the data volume is created. If the volume already existed, create the database by hand:
`docker compose exec postgres createdb -U gym gym_testing`.
Alternatively, `docker compose down -v` deletes **all** volumes (every local database and file) and starts clean. Never run `down -v` casually.

**The queue or scheduler keeps restarting.** They crash until `vendor/` exists and `.env` is configured. Check with `docker compose logs queue`.
