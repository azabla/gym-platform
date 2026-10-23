# ADR 0003: Multi-tenancy with organization scoping

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Every gym (organization) on the platform keeps private data: members and their phone numbers, payments, attendance. One gym must never see another's data, and a single forgotten filter must not be enough to leak it. Most gyms have one branch, but some have several, and their staff do not always work at every location.

The options considered for isolation:

1. **A database (or schema) per gym.** Strong isolation, but every migration runs once per gym, reporting across gyms is hard, and thousands of small gyms make it expensive to operate.
2. **One shared database with `organization_id` on every gym-owned table**, and the application filtering every query. Simple to operate; isolation depends on the filter never being forgotten.
3. **Option 2 plus PostgreSQL row-level security (RLS)**, so the database applies the filter itself. The strongest, but it needs a separate non-owner database role, a session setting per request and queue job, and special handling for migrations, seeders and tests.

## Decision

Use one shared database (option 2), built so that RLS (option 3) can be added later without changing the data model.

### Gym-owned data

- Every gym-owned table has `organization_id`, and its model uses the `BelongsToOrganization` trait (enforced by `tests/Unit/ArchitectureTest.php`):
  - A global scope limits every query to the current organization. It **fails closed**: a query with no current organization throws `NoCurrentOrganization` instead of returning every gym's rows.
  - New rows get the current organization. Creating a row for a different organization while acting as one throws `OrganizationMismatch`.
  - `acrossOrganizations()` is the only way to query across gyms, so platform-wide code is explicit and easy to find.
- `organization_id` is never mass assignable.
- Tables that link two gym-owned records (such as `branch_staff`) also carry `organization_id`, with composite foreign keys into `(organization_id, id)` on both sides, so the database itself refuses links between different gyms.

### The current organization

- `CurrentOrganization` holds the organization a request or queued job acts for. It is a scoped container binding, reset for every request and job, so one gym's context can never leak into the next unit of work.
- `CurrentOrganization::run($organization, $callback)` acts as an organization temporarily (seeders, scheduled tasks) and always restores the previous one.

### Users and staff

- Users are global identities in the Identity domain, which does not depend on Organizations (enforced by the architecture tests).
- A `Staff` record gives a user one role at one organization: Owner, Manager, Receptionist or Trainer. One login can therefore work at several gyms.
- A staff member works at all branches (`works_at_all_branches`) or only at the branches they are assigned to. The flag is explicit, so an empty assignment list never means "everywhere". Owners always work at all branches (a database check constraint).

### Deferred

- **Row-level security** as a database-level safety net. Revisit with the production Docker stage, when a non-owner database role exists.
- **Choosing the organization for an HTTP request** (subdomain or path), in the sign-in work.
- **Queued jobs:** carry the organization id in hidden `Context` and restore `CurrentOrganization` when the job runs, once the first gym-specific job exists.
- **More roles:** Accountant arrives with Billing, when its permissions are defined. Platform admins (our own support staff) are not a gym role and are handled with sign-in.
- **A different role per branch** for the same person.

## Consequences

- A forgotten filter cannot leak data through Eloquent: the query fails loudly instead.
- Every query on gym data needs a current organization. Tests call `actingAsOrganization()`, seeders and scheduled tasks use `CurrentOrganization::run()`, and cross-gym code says `acrossOrganizations()`.
- The query builder (`DB::table(...)`) and raw SQL bypass the scope. Use Eloquent for gym data until RLS exists.
- Each new domain adds its models namespace to the architecture test, so the rule follows the codebase as it grows.
