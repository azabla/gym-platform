# ADR 0022: Back from Chapa, and the gym in every push

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

The members app (Volt) runs on this API since Volt-Fitness #1. Two gaps showed up:
- **No return address.** Chapa got only a `callback_url` (ADR 0009). After paying, the member stayed on Chapa's page and had to close the app's checkout by hand.
- **The gym by number.** Pushes named the gym by `organization_id`, its number (ADR 0012). The app knows gyms by slug (`/me/gyms/{slug}`), so a push for another of the member's gyms couldn't switch to that gym.

## Decision

- **A return page.**
  - Chapa gets `return_url`, the plain page `/payments/returned`. It says the payment is being confirmed and points back to the app, with no amount or name.
  - Reaching it proves nothing about the payment, so the app still has the API check with Chapa (`POST …/payment-links/{id}/check`).
  - A payment link in the members app shows `return_url` while it is pending, so the app can close its checkout when the member lands there.
  - Links staff send by SMS land on the same page.
- **The gym's slug in every push.** Push data gains `gym`, the gym's slug. `organization_id` stays as it was, for anything already reading it.

## Consequences

- After paying, the app's checkout closes by itself; the member no longer has to leave it.
- A tapped push switches to the right gym before opening what it is about.
- The return URL is the same for every link, so it says nothing about which payment it was.
