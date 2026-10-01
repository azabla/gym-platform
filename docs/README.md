# Gym Platform: Developer Documentation

## Getting started

Laravel is scaffolded and dependencies are locked in `composer.lock`. For a new checkout, follow [Cloning an existing checkout](development-setup.md#cloning-an-existing-checkout). After that initial setup, `make up` starts the environment and `make` lists every command.

## Where things live

```text
docker/                    Dockerfile, nginx, Postgres init scripts, storage config
app/Domains/<Domain>/      Business code, one folder per domain
app/Domains/Shared/        Small, framework-free building blocks used by every domain (Money, ...)
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

## Glossary

| Term | Meaning |
|---|---|
| Organization | The gym business. The tenant. |
| Branch | A physical location of an organization. |
| User | An identity that can log in. |
| Member | A gym customer. May or may not have a User. |
| Staff | A gym employee (has a User). |
| Plan | A membership offering (e.g. Monthly Premium). |
| Membership | A member's actual purchased period of a plan. |
| Invoice | Money owed. |
| Payment | Money received. |
| Minor units | The smallest currency unit. 1 ETB = 100 santim. |

## Architecture Decision Records

| # | Decision |
|---|---|
| [0001](adr/0001-money-as-integer-minor-units.md) | Represent money as integer minor units |
| [0002](adr/0002-docker-development-environment.md) | Custom Docker Compose development environment |

Write a new ADR whenever a decision is expensive to reverse. Copy the structure of an existing one: Context, Decision, Consequences.

## Running tests and checks

```bash
make test                                   # all tests
make artisan c="test --filter=Money"        # one group
make check                                  # style + static analysis + tests (what CI runs)
```
