# ADR 0046: Money safety: who did what, cash counts and lost phones

- **Status:** Accepted
- **Date:** 2026-11-28

## Context

Receptionists handle a gym's cash, and owners ask three things the platform couldn't answer:

- **Who changed the money?** Who voided this payment, gave this refund, backdated this sale or changed this price? Records named who received, voided or sold something, but nothing kept the history of changes or showed it in one place.
- **Does the drawer match?** Does the cash in the drawer match what the system took? Nothing compared the two.
- **What about a lost phone?** Sign-in tokens never expired and couldn't be signed out by hand.

## Decision

### The money log

- **What it records.** `activity_records` keeps who did what with a gym's money, at which branch, what it was about and what changed:
  - payments received, refunded and voided;
  - memberships sold, cancelled and frozen by staff;
  - member discounts given and removed;
  - payment links cancelled or marked refunded;
  - plan prices;
  - promo codes and discount groups;
  - cash counts closed and checked.
- **Written with the action.** Each entry is written in the same transaction as its action, so money never changes without its record. Amounts are kept as written ("2,500.00 ETB") and dates as "2026-11-28", so an entry reads the same however prices change later.
- **Where it lives.** The writing is a small `Activity` domain that every domain calls with `Activity::record()`. This is the one place a domain calls another directly rather than announcing an event: an event dispatched after commit could be lost, and the log must not be. `Activity` depends on no domain but Organizations, Identity and Shared, like a kernel, and the ArchitectureTest holds that.
- **Forms.** Plans, promo codes and discount groups are saved straight from forms. The `LogsMoneyChanges` trait logs their creation, and any change to what they charge, with the staff member signed in.
- **Never changed.** Entries can't be changed or deleted: the model refuses, and a database trigger refuses too.
- **Flags.** An entry is flagged when an owner would want to look:
  - refunds, voids and cancellations;
  - a payment dated before today;
  - a sale backdated, discounted or below the plan's price;
  - a cash count that is off.

  Memberships an import brings in (ADR 0044) began long ago and aren't flagged.
- **Who sees it.** The panel's **Activity** list (under Money) and a **History** section on each payment, membership, member, payment link, plan, promo code and discount group page are for:
  - owners, who see everything;
  - managers, who see their branches' entries and the gym-wide ones such as plan prices.

  Other staff don't see the log.

### Cash counts

- **What a count expects.** A staff member closes their own count at a branch. It covers the cash they took there since their last count: cash payments less cash refunds, not voided.
- **Closing.** Closing locks those payments and points each at its count, so the same cash is never counted twice; cash taken afterwards goes to the next count. Several counts a day are fine, one per shift.
- **Differences.** A count that is off needs a note, and it is flagged in the log. A closed count isn't changed: a payment voided after it was counted leaves the count as it was, and the void shows in the log.
- **Checking.** Owners and managers mark a count checked, with an optional note, once. Nobody checks their own count but an owner.
- **Who sees counts.** Receptionists see their own. Owners, managers and accountants see everyone's at their branches.
- **Where.** In the panel: **Cash counts**, with **Close my cash** showing what the staff member should have and the difference as they type, plus a dashboard reminder of cash still to count and counts to check. In the staff app: `cash-counts`, `cash-counts/expected` and `cash-counts/{id}/check`.

### Lost phones

- **Which app.** Each sign-in records its app: the staff app (signed in with a password) or the members app (with an SMS code).
- **Expiry.** A sign-in unused for 30 days (staff app) or 90 (members app) stops working on its next request, and each use moves its time on. Sign-ins from before this have no app and get the staff app's 30 days.
- **Nightly cleanup.** `sign-ins:end-unused` deletes expired sign-ins nightly at 01:30.
- **Signing out.** Anyone signs out their own phones, one or all, from **My devices** in the panel's user menu, or `GET/DELETE /me/devices` in either app. Owners and managers can sign a staff member out of the staff app on every phone; their members-app sign-ins are theirs and stay.
- **QR credentials.** However a sign-in ends, Identity announces `SignInEnding` before deleting it, and the QR credentials issued to that phone are revoked (ADR 0011).
- **Browser sessions.** Panel sessions on a browser already end after 120 minutes and aren't touched.

## Consequences

- **The owner can see.** An owner sees every money action and who made it, with the ones worth a look flagged, and can compare each staff member's drawer with what the system took.
- **No history before this.** The log begins with this change: earlier payments and sales have no entries.
- **A new money action must log itself.** Any new action that moves money calls `Activity::record()` inside its transaction (CLAUDE.md says so).
- **Cash only.** Telebirr, CBE Birr and bank transfers aren't counted, as the gym's bank statements already show them.
- **Lost phones end on their own.** A lost staff phone stops working within 30 days, or at once when signed out.

### Deferred

- An opening float and cash handed to the owner during the day.
- Counting non-cash methods against bank statements.
- A money-log report as CSV and in the staff API.
- Signing out panel browser sessions.
