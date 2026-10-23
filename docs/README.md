# Gym Platform: Developer Documentation

## Getting started

Laravel is scaffolded and dependencies are locked in `composer.lock`. For a new checkout, follow [Cloning an existing checkout](development-setup.md#cloning-an-existing-checkout). After that initial setup, `make up` starts the environment and `make` lists every command.

## Where things live

```text
docker/                    Dockerfile, nginx, Postgres init scripts, storage config
app/Domains/<Domain>/      Business code, one folder per domain
app/Domains/Shared/        Small, framework-free building blocks used by every domain (Money, PhoneNumber, ...)
app/Domains/Identity/      Users: identities that can log in, independent of any gym
app/Domains/Organizations/ Gyms, branches and staff; Tenancy/ keeps each gym's data separate
app/Domains/Members/       Gym customers: registering, finding, editing and archiving members
app/Domains/Memberships/   Plans a gym sells, and the memberships staff sell from them
app/Http/                  The API: controllers, form requests, middleware and resources (v1)
routes/api.php             API routes, versioned under /api/v1
tests/Unit/Domains/...     Unit tests, mirroring app/Domains
tests/Feature/...          Tests that boot Laravel and touch the database
docs/adr/                  Architecture Decision Records (why we chose what we chose)
```

Tests live under `tests/` (mirroring the domain folders) instead of inside each domain. This keeps test code out of the production autoloader and works with Laravel's default PHPUnit/Pest configuration.

## Conventions

1. Every PHP file in `app/Domains` starts with `declare(strict_types=1);` (enforced by `tests/Unit/ArchitectureTest.php`).
2. Money is always a `Money` object in code and `amount_minor BIGINT` + `currency CHAR(3)` in the database. Never a float. See ADR 0001.
3. Value objects are `final readonly` and every "change" returns a new instance.
4. Code in `app/Domains/Shared` must not depend on Laravel.
5. Named constructors (`Money::of()`, `Money::ofMinor()`) instead of public constructors, so every way of creating an object is explicit and validated.
6. Gym-owned models use `BelongsToOrganization`, so every query is limited to the current organization (enforced by `tests/Unit/ArchitectureTest.php`). `organization_id` is never mass assignable. See ADR 0003.
7. Tables that link two gym-owned records carry `organization_id` and use composite foreign keys, so the database refuses links between gyms.
8. Gym-specific API routes sit under `/api/v1/organizations/{slug}` inside the `organization` middleware group. See ADR 0004.
9. Phone numbers are a `PhoneNumber` in code and an E.164 string (`+251911234567`) in the database, never stored as typed. See ADR 0005.
10. Anything that depends on "today" uses the gym's date, `Organization::today()`, never the server's (UTC) date. See ADR 0006.

## Glossary

| Term | Meaning |
|---|---|
| Organization | The gym business. The tenant. |
| Branch | A physical location of an organization. |
| User | An identity that can log in. |
| Member | A gym customer, registered at a home branch but welcome at every branch of the gym. May or may not have a User. |
| Staff | A User's role at one organization: Owner, Manager, Receptionist or Trainer. Works at all branches or only at assigned ones. |
| Plan | What a gym sells: a price and a length in days or months (e.g. Monthly, 2,500 ETB). |
| Membership | A plan sold to a member for set dates, both included. A member has one membership at a time. |
| Invoice | Money owed. |
| Payment | Money received. |
| Minor units | The smallest currency unit. 1 ETB = 100 santim. |

## Architecture Decision Records

| # | Decision |
|---|---|
| [0001](adr/0001-money-as-integer-minor-units.md) | Represent money as integer minor units |
| [0002](adr/0002-docker-development-environment.md) | Custom Docker Compose development environment |
| [0003](adr/0003-multi-tenancy-with-organization-scoping.md) | Multi-tenancy with organization scoping |
| [0004](adr/0004-api-sign-in-and-organization-per-request.md) | API sign-in and choosing the organization per request |
| [0005](adr/0005-members-and-phone-numbers.md) | Members and phone numbers |
| [0006](adr/0006-plans-and-memberships.md) | Plans and memberships |

Write a new ADR whenever a decision is expensive to reverse. Copy the structure of an existing one: Context, Decision, Consequences.

## Running tests and checks

```bash
make test                                   # all tests
make artisan c="test --filter=Money"        # one group
make check                                  # style + static analysis + tests (what CI runs)
```
