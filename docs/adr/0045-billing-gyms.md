# ADR 0045: Billing gyms for the platform

- **Status:** Accepted
- **Date:** 2026-11-28

## Context

The platform opens and pauses gyms (ADRs 0042 and 0043) but couldn't charge them. Nobody knew what a gym owed, and pausing one for not paying was a decision an admin made with nothing to point at. This piece gives each gym a plan, issues invoices, records what the gym pays, warns its owners, and pauses a gym that doesn't pay, then reopens it once it has.

## Decision

### Plans and a gym's terms

- **Plans.** A platform plan has a monthly price for the first branch, a monthly price for each extra branch, and a discount for paying 3, 6 or 12 months in one invoice (0–50%).
  - Prices include VAT.
  - Plans belong to the platform, not to a gym.
  - Retiring a plan keeps the gyms already on it, but it isn't offered for new ones. A plan in use can't be deleted.
- **A gym's subscription.** A gym is billed once it has one. It holds:
  - the plan, with negotiated prices in place of the plan's when there are any;
  - the months each invoice covers;
  - the trial's end;
  - the first day of the next period to invoice;
  - the grace in days (default 14);
  - the gym's TIN.

  Changes apply from the next invoice.
- **Trial.** A new gym gets 30 days by default, set when it is opened and extendable until its first invoice is issued.
- **Existing gyms** aren't billed until an admin sets up billing with a first billing day. `gyms:create` makes unbilled gyms too.

### Invoices

- **Timing.** An invoice is issued 7 days before its period starts, is due on the period's first day, and pauses the gym on its pause day: the due day plus the gym's grace.
- **Fixed once issued.** An invoice keeps what it was issued with: plan name, branches counted that day, prices, discount, total, VAT and TIN.
  - A wrong one is voided with a reason, never edited.
  - Voiding the latest invoice issues its period again on the next run, with the terms as they are then.
  - An invoice with payments can't be voided.
- **The amounts.** VAT is the 15% the total already holds: `round(total × 15 / 115)`.
- **Numbers.** Invoice numbers run across the whole platform (`INV-000123`), from `platform_counters`, inside the issuing transaction.
- **One invoice per period.** A unique index allows only one invoice that isn't voided per period start, so an invoice is never issued twice.
- **No backdated periods.** A period never starts in the past. If its first day went by without an invoice, because the gym was paused or no run happened, it starts on the day it is issued. While a gym is paused, for any reason, nothing is issued, so it isn't charged for time it couldn't use.
- **The page.** The invoice is a printable page, not a PDF (no package): Ethiopian date beside the Gregorian, in English for admins and in the owner's language for owners.
- **Platform details.** The platform's name, TIN, address, phone and accounts to pay into come from `config/platform.php` (`PLATFORM_*` in the environment).

### Payments

- **By hand.** Gyms pay by bank transfer, Telebirr, CBE Birr or cash. A platform admin records each payment on the invoice from the gym's receipt.
- **Rules.** An invoice may be paid in parts, never more than is owed. Each transaction number is recorded once per method, and cash has none.
- **Paid.** Once the payments reach the total, the invoice is paid.
- **Online payment** is left for later.

### Pausing and reopening

- **The run.** `platform-billing:run` runs hourly over every billed gym, paused ones included: it is the one scheduled job that must still see them. For each gym, in its own today, it:
  1. issues what has come due;
  2. sends the reminders;
  3. pauses the gym if an invoice is still unpaid on its pause day.

  A missed run is caught up on the next.
- **The pause itself.**
  - It goes through Organizations: `Organization::suspendForUnpaidBill()`.
  - `gym_suspensions.cause` records `unpaid_bill`, with no admin; an admin's pause is `by_admin`, naming the admin.
  - The pause history says "by the platform".
- **Reopening.**
  - Paying the invoice in full reopens a gym paused for an unpaid bill at once, once nothing else unpaid is past its pause day.
  - An admin can **give more time**, which moves the invoice's pause day and reopens the gym until then.
  - An admin's pause is never ended by a payment. An admin may resume an unpaid-bill pause by hand.
- **Each invoice pauses its gym once** (`paused_gym_at`). A gym an admin resumed isn't paused again by the next run for the same invoice.
- **Owners still get in.** An owner of a gym paused for an unpaid bill can still sign in. The paused page shows them what is owed, the accounts to pay into and the printable invoice. Other staff see only that the gym is paused.

### Telling the owners

- **Who and how.** Only owners hear about the bill. The messages always go to the inbox, by push and by SMS, under the topic `platform_bill`. It isn't one staff choose, so `notification-preferences` doesn't list it.
- **The messages:**
  - the invoice issued;
  - due today, unless it was issued that day;
  - a week overdue;
  - the gym pauses tomorrow;
  - paused;
  - paid, or paid with the gym open again.
- **When.** Each reminder goes once. Messages wait for 07:00–21:00 where the gym is. A pause never holds them back, since the gym needs them most when it is paused.
- **The panel.** Owners have a **Platform bill** page with their plan, next invoice, invoices and how to pay. While an invoice is waiting, a banner across every page gives its amount and due day, and once overdue, the day it pauses the gym.

### Where it lives

- **The domain.** A new `PlatformBilling` domain depends on Organizations only, and no domain depends on it.
- **Figures only.** The admin panel shows invoices, payments and gym statuses, and a dashboard of figures: owed, overdue gyms, collected this month, gyms on trial. Never a gym's members.

## Consequences

- **Platform income.** The platform can charge every gym and see who owes what at a glance. A gym that doesn't pay stops by itself, with warnings first, and starts again by itself once it pays.
- **Admins enter every payment by hand.** It is right for a few dozen gyms, and online payment can follow.
- **On today's server.** On staging's free plan (ADR 0020) the hourly run happens only while the service is awake. Invoices, reminders and pauses then come late but never twice, and a period never starts in the past.

### Deferred

- Paying online through the platform's own Chapa account.
- Pricing by active members.
- Credit notes and refunds of payments.
- Emailed invoices, and a PDF.
