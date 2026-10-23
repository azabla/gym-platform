# ADR 0004: API sign-in and choosing the organization per request

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

The first client is a Flutter app; a web dashboard for the front desk comes later. Staff sign in with an email and a password. One person can work at several gyms (ADR 0003), so every gym-specific request has to say which gym it is for, and the server has to check that the signed-in user really works there before any gym data is touched.

The options considered for choosing the gym:

1. **A subdomain per gym** (`demo-gym.example.et`). Branded, but it needs wildcard DNS and TLS, per-subdomain cookies and extra local setup, and it is awkward for a mobile app.
2. **A header** (`X-Organization: demo-gym`). Invisible in URLs and logs, easy to forget, and harder to test and debug.
3. **The URL path** (`/api/v1/organizations/demo-gym/...`). Explicit, the same for every client, and needs no infrastructure.

## Decision

### Sign-in

- Laravel Sanctum personal access tokens, one per device (`device_name`), sent as `Authorization: Bearer <token>`.
- `POST /api/v1/auth/tokens` checks the email and password through the web guard, inside Laravel's `Timebox`, so an unknown email and a wrong password take the same time and get the same 422 answer. Attempts are limited to five a minute per email and IP.
- `DELETE /api/v1/auth/tokens/current` signs out by revoking the token.
- Emails are stored and matched in lowercase.
- Polymorphic columns store aliases (`user`) instead of class names (`Relation::enforceMorphMap`).

### Choosing the organization: the URL path (option 3)

- Gym-specific routes live under `/api/v1/organizations/{slug}/` and use the `organization` middleware (`ActAsOrganization`). It finds the signed-in user's staff record in that gym, makes the gym `CurrentOrganization` and the staff record `CurrentStaff`, then removes the slug from the route parameters.
- An unknown gym and a gym the user does not work at both get the same 404, so slugs cannot be probed. Every API 404 says "Not found." without naming a model.
- The middleware runs **before route-model binding** (`prependToPriorityList` in `bootstrap/app.php`), so models bound from the URL are already limited to the current gym. Another gym's record id is a 404, never a leak.

### Authorization

- Policies live in the domains (for example `Organizations/Policies/BranchPolicy`) and are attached with `#[UsePolicy]`. They read `CurrentStaff` to check the staff member's role and branches.
- `GET /api/v1/me` lists every gym the user works at with their role, so a client can let them choose one.

### Shape

- Everything is versioned under `/api/v1`. Responses use Eloquent API resources with a `data` wrapper.
- Controllers, form requests, middleware and resources live in `app/Http`. The domains never depend on it (enforced by the architecture tests).

### Deferred

- **The web dashboard:** Sanctum's cookie-based SPA authentication on the same routes, when we build it.
- **Phone sign-in and SMS codes**, with the SMS work.
- **Token lifetime:** tokens do not expire and are revoked on sign-out. Revisit expiry and rotation with the Flutter app.
- **Password reset**, which needs email or SMS delivery.
- **Platform admins** (our own support staff).
- **Subdomains** for branded web addresses, which can be added on top of the path.

## Consequences

- Any client, including curl, can use the API with a token and a gym slug, and the URL always shows which gym a request is for.
- A URL cannot reach another gym's records: the gym is chosen before any model is loaded, and the scope from ADR 0003 hides other gyms' rows.
- Every gym-specific route must sit inside the `organization` middleware group. A route outside it fails closed with `NoCurrentOrganization` instead of leaking data.
- Tokens stay valid until revoked, so a lost device must be signed out by revoking its token. A screen for managing tokens is future work.
