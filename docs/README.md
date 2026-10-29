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
app/Domains/Billing/       Payments and refunds against memberships, receipts and balances; Chapa/ talks to each gym's Chapa account
app/Domains/Notifications/ Sending messages: SMS through AfroMessage, or to the log in development
app/Domains/Announcements/ Announcements from a gym to its members, now or scheduled
app/Http/                  The API: controllers, form requests, middleware and resources (v1); Me/ folders hold the members app's side
app/Filament/Staff/        The staff panel at /staff (Filament): its user, tenant middleware, dashboard, widgets and lists
app/Dashboards/            Figures drawn from several domains, shared by the API and the staff panel
app/Providers/Filament/    The staff panel's configuration
app/Console/Commands/      Artisan commands, such as the check of open payment links
routes/api.php             API routes, versioned under /api/v1
routes/console.php         Scheduled tasks, run by the scheduler container
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
11. The staff panel shows and does only what the API allows: it goes through the same policies, its figures come from the same code (`App\Dashboards`), and the rules for a change live in the domain as `refusalTo…` methods that both call. Domains never depend on it. See ADR 0017 and ADR 0018.

## Glossary

| Term | Meaning |
|---|---|
| Organization | The gym business. The tenant. |
| Branch | A physical location of an organization. |
| User | An identity that can log in: staff with an email and a password, members with a code sent to their phone. |
| Staff panel | The website at /staff where staff sign in with their email and password, choose a gym, see its dashboard and lists, and work the front desk: members, sales, freezes, payments and check-ins (ADR 0017 and ADR 0018). |
| Members app | The app where members see their memberships, what they owe and their receipts, and pay with Chapa. It shows every gym where their phone number is registered (ADR 0010). |
| Sign-in code | A 6-digit code sent by SMS that signs a member in to the members app. It works for 10 minutes and 5 tries. |
| QR credential | What lets a member's phone show their QR code at one gym: a secret given to the phone once, from which it makes a code every 30 seconds (GQ1), even offline. One phone per member (ADR 0011). |
| Notification | A message to a member at one gym: in the app's inbox, by push when they allow it, and by SMS for expiry. See ADR 0012. |
| Announcement | A message from a gym to its members in its own words, to every branch or one, sent now or at a set time, in the inbox and by push (ADR 0015). |
| Check-in | A member coming through the door, by scanning their QR code or at the desk. Every attempt is kept, allowed or denied, with the reason when denied. |
| Member | A gym customer, registered at a home branch but welcome at every branch of the gym. May or may not have a User. |
| Staff | A User's role at one organization: Owner, Manager, Receptionist, Trainer or Accountant. Works at all branches or only at assigned ones. |
| Plan | What a gym sells: a price and a length in days or months (e.g. Monthly, 2,500 ETB), valid at all branches or only some, with a number of days it can be frozen. |
| Membership | A plan sold to a member for set dates, both included. A member has one membership at a time. |
| Visit pack | A plan that allows a number of visits within its dates, such as 10 visits in 60 days. A visit is a day with a check-in that let the member in (ADR 0016). |
| Opening hours | When a branch is open, by weekday, in the gym's time zone. A branch without any is always open. Check-ins outside them are turned away. |
| Freeze | A pause in a membership. The membership's end date moves later by the number of frozen days. |
| Discount | A reduction of a plan's price at sale, as an amount or a percentage, with a reason. Only owners and managers can give one. |
| Payment | Money a member paid towards a membership, by cash, Telebirr, CBE Birr or bank transfer, or online through Chapa. A membership can be paid in parts. |
| Chapa account | A gym's own account with Chapa, the payment gateway. The owner connects it with its secret key, and members' online payments go straight to it (ADR 0009). |
| Payment link | A link to Chapa's payment page for an amount owed on a membership, or for a plan a member is buying in the app (ADR 0013). Staff send it to the member, and the payment is recorded once Chapa confirms it. Marked refund needed if paid when nothing was due any more. |
| Refund | Money given back on a cancelled membership, up to what was paid. Stored as a payment going the other way. |
| Void | Marking a payment or refund as recorded by mistake. It stays on record but no longer counts. |
| Receipt | The record of a payment or refund for the member, numbered 1, 2, 3 in each gym with no gaps. |
| Balance due | What is still owed on a membership: its price, less payments, plus refunds. A cancelled membership owes nothing more. |
| Invoice | Money owed for something other than a membership. Not built yet: payments go against memberships for now (ADR 0008). |
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
| [0007](adr/0007-freezing-branch-only-plans-and-discounts.md) | Freezing, branch-only plans and discounts |
| [0008](adr/0008-billing-and-payments.md) | Billing and payments |
| [0009](adr/0009-online-payments-with-chapa.md) | Online payments with Chapa |
| [0010](adr/0010-members-app-sign-in-and-payments.md) | The members app: SMS sign-in, seeing memberships, paying |
| [0011](adr/0011-check-ins-and-rotating-qr-codes.md) | Check-ins and rotating QR codes |
| [0012](adr/0012-reminders-and-notifications.md) | Reminders and notifications |
| [0013](adr/0013-buying-a-membership-in-the-app.md) | Buying a membership in the app |
| [0014](adr/0014-dashboards.md) | Dashboards |
| [0015](adr/0015-announcements.md) | Announcements |
| [0016](adr/0016-visit-packs-and-opening-hours.md) | Visit packs and opening hours |
| [0017](adr/0017-staff-panel.md) | The staff panel |
| [0018](adr/0018-front-desk-in-the-staff-panel.md) | Working the front desk in the staff panel |

Write a new ADR whenever a decision is expensive to reverse. Copy the structure of an existing one: Context, Decision, Consequences.

## Running tests and checks

```bash
make test                                   # all tests
make artisan c="test --filter=Money"        # one group
make check                                  # style + static analysis + tests (what CI runs)
```
