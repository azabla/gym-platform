# ADR 0013: Buying a membership in the app

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Members are reminded before their membership ends (ADR 0012), and they can pay what they owe on a membership in the app (ADR 0010). To get their next membership, though, they still have to go to the desk.

Selling at the desk creates the membership first, and the member pays it, in full or in parts (ADR 0008). Money owed doesn't stop anyone at the door (ADR 0011), so the same in the app would let anyone in who opens a checkout and walks away.

## Decision

### The membership is created only once it's paid

- The member picks a plan, and the app opens a Chapa payment link for it (`POST me/gyms/{gym}/plans/{plan}/purchase`). Nothing else is created.
- When Chapa confirms the payment, in one transaction with the link locked:
  1. the plan is sold to the member (`Membership::buyInApp()`);
  2. the payment is recorded, as for any Chapa payment (ADR 0009);
  3. the link is attached to the new membership.
- An abandoned checkout leaves nothing behind. The callback, the app's check and the scheduled check (`billing:check-payment-links`) all work as before, so nothing new talks to Chapa.

### Which plans, when, and at what price

- **Plans:** any plan on sale that's valid at the member's home branch, as at the desk (`GET me/gyms/{gym}/plans`). Archived plans aren't offered.
- **Start:** the day after the member's last membership ends, or today if it has ended, worked out when the payment is confirmed. A membership sold at the desk in the meantime is queued before it, never overlapped. Renewing early never loses days.
- **Price:** the plan's price when the link is opened, with no discounts in the app. If the owner changes the price before the member pays, the member gets the plan at the price they paid.
- **Branch:** the payment is for the member's home branch, as for paying in the app.

### Purchase links

- Every payment link now names the member paying it (filled in from the membership for existing links), and is for either a membership or a plan. A check constraint keeps one of the two.
- A member has one open link to buy a plan at a time:
  - asking again for the same plan returns it;
  - choosing another plan cancels the unpaid one. The member cancelled it, so it has no staff member; only purchase links may be cancelled that way.
- **Paid anyway:** a cancelled purchase link that the member had already paid still buys its plan when Chapa confirms it, as a cancelled link for a membership still records its payment (ADR 0009).
- **Member gone:** if the member left the gym before Chapa confirms, nothing is sold, and the link is marked `refund_needed` for the gym to refund from its Chapa dashboard.

### Sold by nobody

- A membership bought in the app has no staff member who sold it. `sold_in_app` marks it, and a check constraint keeps the two together.
- Staff see `sold_by: null` and `sold_in_app: true`; the member sees `sold_in_app: true`.

### Deferred

- Discounts and promotions in the app.
- Choosing a later start date.
- Buying for a branch other than the member's home branch.
- Expiring purchase links that were never paid.

## Consequences

- Members can renew from an expiry reminder without visiting the gym, and the money reaches the gym's own Chapa account.
- A membership exists only once it's paid for, so the app never lets anyone in on credit.
- The desk still handles discounts, part payments, branch-only plans for other branches, and members whose gym hasn't connected Chapa.
- An unpaid purchase link stays open until the member chooses another plan; it costs nothing, because nothing else depends on it.
