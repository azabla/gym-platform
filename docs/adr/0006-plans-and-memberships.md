# ADR 0006: Plans and memberships

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Gyms sell time: a day pass, a month, three months, a year. Front-desk staff need to sell a plan in one step and see at a glance who is active and until when. Later work builds on this: Billing records how a membership was paid, Attendance lets active members in, and Notifications reminds members before their membership ends.

Months have different lengths. Ethiopian months have 30 days, while Gregorian months have 28 to 31. The server runs on UTC, three hours behind Addis Ababa, so the server's date is still yesterday there between midnight and 3 a.m.

## Decision

### Plans

- A plan has a name, an optional description, a price (Money, ADR 0001) and a length in days or calendar months. The gym chooses for each plan, so a gym that counts 30-day months uses 30 days.
- Membership dates include both the first and the last day. A 1-month membership starting 15 March runs through 14 April. If the next month is too short for the start day, the membership runs through that month's last day: starting 31 January, it runs through 28 February.
- Plan names are unique within a gym, ignoring capitals.
- Archiving a plan (a soft delete) stops new sales and frees its name. Memberships already sold keep their dates and price.

### Memberships

- A membership is a plan sold to a member for set dates. The price is copied from the plan at the sale, so changing a plan's price only affects new sales.
- A member has one membership at a time. Selling to a member who is still active queues the new membership: it starts the day after the current one ends.
  - A PostgreSQL exclusion constraint refuses overlapping memberships for the same member. Cancelled memberships don't count.
  - The constraint needs the `btree_gist` extension, which the migration creates. It has been a trusted extension since PostgreSQL 13, so the database owner can create it without superuser rights.
- A sale starts today by default. Staff can pick a later date. Only owners and managers can pick a past date, to enter memberships members already had before the gym used the platform.
- The status (upcoming, active, expired or cancelled) is worked out from the dates whenever it is needed. It is never stored, so nothing has to run at midnight.
- Each membership records which staff member sold it.
- Cancelling needs a reason. The membership stays on record with who cancelled it and when, and its days become free for a new membership.
- Each member in the API shows one membership at a glance: the one valid today, otherwise the next one coming up, otherwise the last one that ended.

### Today

- Each gym has a time zone, `Africa/Addis_Ababa` by default.
- `Organization::today()` returns the gym's date. Anything that depends on the day uses it, never the server's date.

### Access

- Every staff role can see plans and memberships.
- Owners, managers and receptionists sell memberships.
- Only owners and managers create plans, change prices, archive plans and cancel memberships, so a sale can't be quietly undone after the money is taken.

### Deferred

- Freezing memberships, plans valid only at some branches, and discounts at sale. These come in the next piece of work.
- Recording payments and refunds, in Billing.
- Switching plans with credit for unused days.
- Renewal reminders, in Notifications.
- Letting members in only while their membership is active, in Attendance.
- Showing dates in the Ethiopian calendar, in the apps.
- Filtering members by membership status, for example to call those whose membership has just ended.
- Restoring archived plans.

## Consequences

- To sell a membership, an app sends only the member and the plan. The API works out the dates and the price.
- Two staff members selling to the same member at the same moment can't create overlapping memberships, because the database refuses the second sale.
- Changing a plan never changes what members already bought.
- A member can't hold two memberships at once, for example a gym plan and a separate class pass. Add-ons like that will need their own design.
- Reports and reminders can use the stored dates directly.
