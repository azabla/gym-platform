# ADR 0009: Online payments with Chapa

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Billing (ADR 0008) records money taken at the front desk. Members still have to come in, or send a Telebirr transfer and wait for staff to type its transaction number. ADR 0008 chose Chapa, a payment gateway, for online payments: one integration covers Telebirr, CBE Birr, M-Pesa, cards and the other channels Chapa accepts.

To take a payment through Chapa's API:

1. we start a checkout (`POST /v1/transaction/initialize`), and Chapa gives the address of its payment page (`checkout_url`);
2. once the customer has paid, Chapa calls our `callback_url` with the transaction's reference (`trx_ref`);
3. we ask Chapa how the transaction stands (`GET /v1/transaction/verify/{tx_ref}`). Chapa's docs say to always check this before treating a payment as real.

Every request carries the Chapa account's secret key. Test keys (`CHASECK_TEST-…`) take no real money.

Members have no app yet. It is the next piece, so staff need a way to get Chapa's page to the member themselves.

## Decision

### Each gym connects its own Chapa account

- The owner pastes the gym's secret key from the Chapa dashboard (`PUT chapa-account`). We check it with Chapa before saving it, so a mistyped key, or the public key pasted by mistake, is caught at once.
- Members pay into the gym's own account, so the platform never holds their money and never has to pay it out. Each gym has its own agreement with Chapa and pays Chapa's fee.
- The key is stored encrypted with the app key (Laravel's `encrypted` cast), so a copy of the database alone doesn't reveal it. It is never returned: responses show only whether it is a test or a live key, its last 4 characters, and who connected it and when.

### Payment links

- Staff create a payment link for one membership (`POST memberships/{id}/payment-links`). It asks for everything still due, or a smaller amount staff choose, like a part payment, and follows the same branch rules as a payment.
- We start a checkout on Chapa with the gym's key:
  - the member's name, phone number (in the `09…` form Chapa expects) and email;
  - the gym's name and the plan as the page's title and description, trimmed to what Chapa accepts;
  - our own transaction reference, `tx_ref`: `GP-` followed by a ULID, unique across all gyms.
- Staff send the link's `checkout_url` to the member themselves, by WhatsApp, Telegram or SMS. A link shows its checkout URL only while it is open.
- A membership has at most one open link (a partial unique index). Staff cancel a link when the member pays another way, and can then create a new one.
- If Chapa refuses the checkout or doesn't answer, the API answers 502 with the reason (Chapa's own message when it refused), and nothing is saved.

| Role | Connect or disconnect Chapa | See whether it's connected | Create, check, cancel and see links |
|---|---|---|---|
| Owner | Yes | Yes | Yes |
| Manager, Accountant | No | Yes | Yes |
| Receptionist | No | No | Yes |
| Trainer | No | No | No |

### Confirming a payment

- Chapa's callback (`/api/v1/chapa/callback`, by GET or POST) is public, so anyone could call it. It is only a signal: we find the link by its `trx_ref`, act as the link's gym, and ask Chapa ourselves with that gym's key. The callback is limited to 60 calls a minute from each address.
- The payment is recorded only when Chapa says the transaction succeeded, for the link's `tx_ref`, for exactly the link's amount and currency, and gives its own reference. Anything missing or unexpected in Chapa's answer counts as not paid.
- If Chapa knows of no payment yet (it answers 400 or 404), the link stays open.
- Recording locks the link and does nothing if it was already settled, so a callback that arrives twice, or together with a check, records one payment.

### What gets recorded

- An ordinary payment (ADR 0008), so balances, receipts and the day's totals work unchanged. It has:
  - the new method `chapa`, which gets its own line in the totals;
  - Chapa's reference as its transaction number, kept exactly as Chapa gives it;
  - the gym's next receipt number, the gym's date and the link's branch.
- Nobody at the desk took the money, so the payment has no staff member who received it. The link records who created it. A check constraint keeps the two together: a `chapa` payment has no staff member, and every other payment has one.
- Staff can't choose `chapa` when recording a payment or a refund by hand, so only Chapa's confirmation creates one.
- The link becomes `paid` and points at its payment, so it shows the receipt number.

### When the money no longer fits: refund needed

- Chapa's page stays open after staff cancel a link here, so a member can still pay a cancelled link, or pay after the membership was paid another way or cancelled.
- If the membership still owes the amount, the payment is recorded as usual, even on a cancelled link.
- If not, Chapa has taken money that isn't due. The link becomes `refund_needed` (listed with `?status=refund_needed`), and the gym refunds it from its Chapa dashboard. No payment is recorded, so there is nothing to refund in the platform.
- Refunding a Chapa payment on a cancelled membership works like any refund (ADR 0008): the money goes back by cash, Telebirr, CBE Birr or bank transfer, and is recorded with that method.

### Backups for a callback that doesn't arrive

- Staff can press "check" on a link (`POST payment-links/{id}/check`), for example when the member says they paid.
- Every 10 minutes, `billing:check-payment-links` checks the open links of the last 7 days, one at a time, each as its own gym and with that gym's key. A link Chapa doesn't answer about is tried again on the next run. Older links can still be checked by hand.
- `withoutOverlapping()` stops a slow run from overlapping the next one.

### The callback and verify, rather than webhooks

- ADR 0008 expected webhooks to confirm payments. Chapa can also send webhooks, but each gym would have to set them up in its own Chapa dashboard.
- The callback needs no setup by the gym, and asking Chapa gives the same answer either way. Webhooks can come later as one more signal that triggers the same check.

### No new packages

- `ChapaClient` (in `app/Domains/Billing/Chapa/`) talks to Chapa through Laravel's HTTP client, with a 15-second timeout. `CHAPA_BASE_URL` sets Chapa's address.
- `ChapaTransaction`, a value object, reads Chapa's verify answer.
- Tests fake Chapa with `Http::fake()` and `Http::preventStrayRequests()`, so they never call it.

### Deferred

- The members app, where members sign in and pay their own links. It reuses payment links.
- Refunds through Chapa's API.
- Chapa's webhooks.
- Sending links by SMS from the platform.
- Direct charge, where Chapa asks the member's phone to approve the payment without a page.
- Links that expire.

## Consequences

- Members can pay from their phones, and their payments are recorded without anyone typing a transaction number.
- A forged or repeated callback can't create a payment: every payment is confirmed by Chapa, with the gym's own key, and recorded once.
- Each gym deals with Chapa directly: its agreement, its fees, its payouts, and refunding links marked refund needed.
- The stored keys depend on the app key. When it changes, the old key must stay in `APP_PREVIOUS_KEYS`; otherwise the stored keys become unreadable, and owners must connect Chapa again.
- When Chapa's callback is lost, a payment can take up to 10 minutes to appear, and a link older than 7 days is checked only by hand.
- The fields we read follow Chapa's docs. Because anything unexpected counts as not paid, a change on Chapa's side shows up as links staying open, not as wrong payments.
