# ADR 0021: API documentation with Scramble

- **Status:** Accepted
- **Date:** 2026-11-05

## Context

The API has 77 operations, for the staff app, the members app and Chapa. Its only documentation was `docs/api/members-app.md`, written by hand, and the curl examples in `docs/development-setup.md`. Whoever builds an app against the API had to read controllers and requests to know each field, and hand-written docs fall behind the code. We want interactive docs, as Swagger gives, that stay true without upkeep, and that aren't open to anyone on the internet.

## Decision

### Generated from the code, with Scramble

- **Scramble** (`dedoc/scramble`) builds an OpenAPI 3.1 document from the routes, form requests, API resources and PHPDoc, with no annotations to keep in step. It is a production dependency, because staging serves the docs.
- **Where:** `/docs/api` is the interactive page (Stoplight Elements, with "Try it"); `/docs/api.json` is the document, for Postman or generating an app's client.
- **What:** every route under `/api` (`config/scramble.php`), titled "Gym Platform API".
- **Tokens:** a document transformer declares one bearer scheme for every call, so the page's **Authorize** box sends a Sanctum token. Signing in (`auth/tokens`, `auth/sms-codes`, `auth/sms-tokens`) and Chapa's callback are marked `@unauthenticated`, as they take none.

### Only for staff, in every environment

- **`LetStaffReadApiDocs`** replaces Scramble's own `RestrictedDocsAccess`, which opens the docs to anyone in the `local` environment and otherwise asks a gate.
  - **Not signed in:** sent to the staff panel's sign-in, which brings them back to the docs.
  - **Signed in to the panel** (the `staff_panel` guard) **as active staff,** by the same rule as the panel, `PanelUser::canAccessPanel()`: the docs.
  - **Anyone else,** such as archived staff: 403.
- **The same in development,** so what works locally works on staging. The docs say which endpoints exist; calling them still takes a token.

### The gym in the path

- **The problem:** the organization middleware reads the `{organization}` slug and removes it from the route (ADRs 0004 and 0010), so no controller method takes it. Scramble matches path parameters to method arguments by position, so on 9 routes it named the slug after the next plain `int` or `string` argument, and lost the real one (`/me/gyms/{notification}/notifications/{notification}/read`).
- **`DocumentTheGymInThePath`,** an operation transformer, keeps the route's own path and parameter names in order, describes `{organization}` as the gym's slug, and keeps the types Scramble found for the others.

### What stays written by hand

`docs/api/members-app.md` remains the members app's contract for what code can't show: the GQ1 QR format, reason codes at the door, push data and the order of a sign-in. It links to the generated docs for each field.

## Consequences

- The docs change with the code: a new route, rule or resource field shows up without editing any doc.
- Scramble reads code statically, so its guesses are as good as the types and PHPDoc: precise return types and resources make better docs. `php artisan scramble:analyze` lists what it couldn't work out. Two warnings remain: `ChapaAccountResource` (its resource may be null) and the audience rules of `UpdateAnnouncementRequest`, which need a current staff member; their other fields are still documented.
- The docs page loads Stoplight Elements from a CDN, so it needs internet access in the browser.
- Someone signed in to the panel can see every endpoint, including other roles'. That reveals no data: each call still checks the token and the role.
