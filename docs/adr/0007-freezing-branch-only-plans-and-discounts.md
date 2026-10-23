# ADR 0007: Freezing, branch-only plans and discounts

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Plans and memberships (ADR 0006) cover a plain sale. Gyms also need to:

- pause a membership while a member travels or is ill, so they don't lose the days they paid for;
- sell plans valid only at some branches, for example a cheaper plan for one location;
- sell below the plan's price, for a student, a promotion or a negotiated deal.

Each of these touches money or access, so the rules have to protect both the member and the gym.

## Decision

### Copied at sale

When a membership is sold, it copies these from its plan along with the price:

- the number of days it can be frozen;
- the branches it is valid at;
- the plan's price, kept as the list price next to the price paid.

Changing a plan later never changes what members already bought.

### Branch-only plans

- A plan is valid at all branches of its gym, or only at chosen ones. This uses `available_at_all_branches` plus a `branch_plan` link table, built like staff branches (ADR 0003).
- A membership stores its own copy, in `valid_at_all_branches` plus `branch_membership`.
- Composite foreign keys keep both link tables inside one gym.
- Check-in (Attendance) will enforce the branches. For now they are stored and shown.

### Discounts

- Only owners and managers can sell below the plan's price, down to free, and they must give a reason.
- Staff type an amount off (`"500"`) or a percentage off (`"10%"`, up to two decimals).
- The `Discount` value object works out the price. It rounds a percentage to the santim, half away from zero (ADR 0001).
- A discount can't be zero, can't be more than the price, and can't round down to nothing.
- Memberships store `list_price_minor`, `price_minor` and `discount_reason`.
- Check constraints keep the price paid at or below the list price, and require a reason exactly when there is a discount.

### Freezing

- **Allowance and permissions:**
  - each plan sets how many days in total its memberships can be frozen (`freeze_days_allowed`, 0 to 366);
  - owners, managers and receptionists can freeze within that allowance;
  - only owners and managers can go beyond it, or start a freeze on a past date.
- **A freeze:**
  - has a first and a last day (both included) and a reason;
  - starts today by default;
  - must start while the membership runs;
  - can't overlap another freeze on the same membership;
  - lasts at most 366 days.
- **Effect on dates:** freezing moves the membership's end date later by the number of frozen days. Every membership of the member queued after it moves by the same number, together with its freezes.
- **Ending early:** when the member comes back early, the freeze ends yesterday, or is removed if it hadn't started. The unused days come off the end dates again.
- **Status:** while a freeze lasts, the membership's status is `frozen`.
- **Order of updates:** dates move in a fixed order:
  - when moving later, the furthest membership moves first;
  - when moving earlier, the nearest moves first.
  - That way the exclusion constraints (one membership per member per day, one freeze per membership per day) never see an overlap partway through an update.

### Deferred

- Checking branches and freezes at the door, in Attendance.
- Reports of the discounts given and the freezes taken.
- Gym-wide freeze rules, for example a minimum length or a fee to freeze.
- Switching plans with credit for unused days.
- Changing the freeze allowance of a membership already sold.

## Consequences

- A member keeps exactly what they bought, even if the gym changes the plan afterwards.
- The front desk can freeze without a manager, within limits the gym set for each plan.
- Every discount and freeze records who gave it and why, which Billing and the Audit domain can build on.
- Freezing a membership that has renewals queued after it updates several rows in one transaction.
- Memberships sold before this work have no freeze days, so only owners and managers can freeze them.
