# ADR 0026: Freezing a membership in the members app

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

A membership can be frozen for the days its plan allows, which pushes its end date later (ADR 0007). Until now only staff could do it, at the desk or in the panel. So a member going away had to call or come in. The members app already showed each membership's freeze days used and left, and its freezes, but couldn't act on them.

## Decision

### What a member can do

- **Freeze their own membership** from today or a later day, through a last day they choose. The end date moves later by the frozen days, as at the desk.
- **End a freeze early,** one going on today or still to come, when they are back sooner. A freeze going on today ends yesterday; one still to come is dropped. Either way the days come back, and so does the end date.

### The rules

- **The same rules as the desk.** `Membership::refusalToFreeze()` and `refusalToEndFreeze()` decide (ADR 0018):
  - not a cancelled membership;
  - starting while the membership runs;
  - no overlap with another freeze;
  - at most a year.

  The messages are the same as at the desk.
- **Two limits only for members:**
  - **Never in the past.** `refusalToFreeze()` takes the gym's today as the earliest first day ("A freeze can start today at the earliest."). Only staff date a freeze in the past, and only owners and managers.
  - **Never beyond the freeze days left.** Only owners and managers go beyond them, at the desk. So a plan with no freeze days can't be frozen from the app.
- **A member's own membership only.** Anything else is a 404, as for the other `me/` routes.

### Who froze it

- **A freeze has no staff member when the member made it.** A new migration makes `membership_freezes.frozen_by_staff_id` nullable; the original migration isn't touched. This is how a membership bought in the app has no seller (ADR 0013).
- **`MembershipFreeze::isFromTheApp()`** says so.
- **Where it shows:**
  - staff see `frozen_in_app` in the API, and "by the member, in the app" in the panel;
  - the members app sees `frozen_in_app` and each freeze's `id`, which it needs to end the freeze.

### Messages

- **No message to the member for their own freeze.** They just made it, so no `membership_frozen` push or inbox message.
- **A staff freeze still sends one,** as before (ADR 0012).

### API

- `POST /me/gyms/{gym}/memberships/{id}/freezes`, with `starts_on` (optional, defaults to today), `ends_on` and an optional `reason`. A missing reason is recorded as "Frozen by the member in the app."
- `POST /me/gyms/{gym}/memberships/{id}/freezes/{freeze}/end`.
- Both answer with the membership as it is now, so the app shows the new end date and the freeze days left at once. Scramble documents them (ADR 0021).

### Deferred

- Discounts in the app.
- Telling the gym's staff when a member freezes. That belongs to staff notifications, which aren't built yet.

## Consequences

- Members going away freeze their membership themselves, and the desk sees who did it.
- Freezing has one set of rules, in the domain, for the desk, the panel and the app.
- A gym that doesn't want members freezing gives its plans no freeze days.
