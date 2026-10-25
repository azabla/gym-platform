# ADR 0016: Visit packs and opening hours

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Plans are a price and a length of time (ADR 0006). Many gyms also sell visit packs, such as "10 visits in 60 days", for members who train a few times a week. Every branch also keeps opening hours. ADR 0011 left both for later, so the door let anyone with a membership in at any hour, and the members app had no `visits_remaining` (ADR 0014).

## Decision

### Visit packs

- **A plan may have a `visit_limit`,** from 1 to 1,000. Null means unlimited, as for every plan before. Owners and managers set it like the other plan fields.
- **It is copied onto the membership when sold,** at the desk or in the app, like the price and freeze days. Changing the plan later changes nothing already sold.
- **A visit is a day,** in the gym's time zone, with at least one check-in that let the member in on that membership. Coming back the same day, at any branch, uses nothing. Check-ins that were turned away never use a visit.
- **Visits are counted from the check-ins,** never stored, so there is no counter to get wrong or to correct. `App\Domains\Attendance\Access\Visits` counts them, with an index on check-ins by membership. It sits in Attendance because Memberships doesn't depend on Attendance.
- **At the door:** a pack with every visit used is turned away with `VISITS_USED_UP` on a day the member hasn't come in yet. There is no override; the member buys another membership, which starts the next day as renewals do (ADR 0013).
- **Shown:**
  - staff see `visit_limit` and `visits_used` on memberships;
  - the members app sees `visits_remaining` on its memberships and home screen;
  - the dashboard's list of members to call also has packs valid today with at most one visit left.
- **The member is told** once, `visits_running_low`, by the check-in that leaves one visit, under the `membership` push topic. Attendance announces `CheckInAllowed` after commit, and `TellTheMember` sends it (ADR 0012).

### Opening hours

- **Per branch, weekly,** in the gym's time zone: one row per open weekday in `branch_opening_hours` (1 is Monday, 7 is Sunday), from `opens_at` until just before `closes_at`.
- **A branch without hours is always open,** as before. Once it has some, a weekday without hours is closed.
- **Closing is after opening on the same day.** Hours past midnight and holiday closures wait.
- **Owners and managers who work at the branch** replace the whole week at once (`PUT branches/{id}/opening-hours`). Every staff member who works there can read it, and the members app lists every branch with its hours (`GET me/gyms/{gym}/branches`).
- **At the door:** `OUTSIDE_HOURS` comes before the membership rules, so a closed branch never uses a visit of a pack. The same rule applies to a desk check-in.

### Reason codes

`OUTSIDE_HOURS` and `VISITS_USED_UP` join Volt's list. The order is now:

1. the QR checks of ADR 0011;
2. `OUTSIDE_HOURS`;
3. `MEMBERSHIP_NONE`, `MEMBERSHIP_EXPIRED`, `MEMBERSHIP_FROZEN`, `BRANCH_NOT_ALLOWED`;
4. `VISITS_USED_UP`;
5. `ALREADY_CHECKED_IN`.

### Deferred

- Off-peak plans, valid only at some hours.
- Hours past midnight, and holiday closures.
- Staff overriding a denial at the desk.
- Visits that count per check-in rather than per day.

## Consequences

- Gyms can sell visit packs, and the door enforces them without anyone counting by hand.
- A member who comes twice in a day pays for one visit, which matches what members expect.
- Each check-in of a pack, and each membership response, adds one query to count visits; the index keeps it small.
- A branch that sets hours turns away early arrivals, including staff checking someone in at the desk.
- The members app must show `OUTSIDE_HOURS` and `VISITS_USED_UP` in its own words, as it does the other codes.
