# ADR 0014: Dashboards

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Staff could list payments, check-ins and members, but nothing answered "how is the gym doing today?" in one place. Owners want the day's takings and the members to call before their membership runs out. The front desk wants check-ins, but shouldn't see the gym's money.

The members app drew its home screen from three calls (memberships, check-ins, the inbox). The Volt doc asks for one, `GET me/dashboard`.

## Decision

### The staff dashboard: `GET organizations/{gym}/dashboard`

One call, read-only, built from data the gym already has:

| Section | What |
|---|---|
| `period` | the days added up: today where the gym is, or `?from=` and `?to=`, at most a year |
| `money` | received, refunded, net and net by method, as the payments list adds them up (ADR 0008), and the open payment links |
| `owed` | what members still owe across their memberships, and how many owe |
| `check_ins` | allowed and denied, and the denials by reason code (ADR 0011) |
| `members` | members with a membership valid today, members registered in the period, and memberships sold at the desk and in the app (ADR 0013) |
| `expiring` | memberships ending in the next 7 days with nothing sold after them, soonest first: the members to call |

**Who sees what:**
- **Money:** owners, managers and accountants see `money`, `owed` and each expiring member's balance. Receptionists take payments but don't see the totals, and trainers don't handle money. A section someone may not see is left out of the answer, not shown empty.
- **Branches:** staff who work at some branches see only those. Payments and check-ins count by their branch, and members by their home branch. `?branch_id=` narrows to one branch the staff member works at.
- **Expiring list:** it uses the same "nothing sold after it" rule as the expiry reminders (ADR 0012), so the list shows who will get a reminder.

### The member's home screen: `GET me/gyms/{gym}/dashboard`

One call for the members app's home screen:

- **`membership`:** the membership that best shows where the member stands today, as in `GET me/gyms` (ADR 0010), with `frozen_until` when a freeze covers today.
- **`next_membership`:** the next one, when already bought or sold.
- **`recent_check_ins`:** the last 5 visits.
- **`balance_due`:** what the member owes at the gym.
- **`open_payment_link`:** any open link, to pay or to buy.
- **`unread_notifications`:** the inbox's unread count (ADR 0012).
- **Left out:** Volt's `visits_remaining` and `grace_ends_on`, because visit packs and grace periods don't exist. Its `outstanding` (invoices) is `balance_due` and `open_payment_link` here.

### Where the code lives

Both dashboards are controllers in the HTTP layer that read from several domains: Billing's totals and balances, Memberships' membership at a glance, and Attendance's check-ins. No domain learns about the others.

### Deferred

- Charts over time (takings by day, check-ins by hour).
- Comparing with the previous period.
- Caching. The numbers are computed on each call, which is fine at a gym's size and keeps them exact.

## Consequences

- An owner sees the day, and who to call, in one screen; the front desk sees the door without the money.
- The members app draws its home screen in one call.
- Every figure comes from the same queries as the lists, so the dashboard and the lists always agree.
- A very large gym asking for a year at once runs several aggregate queries; if that ever gets slow, caching per day is the next step.
