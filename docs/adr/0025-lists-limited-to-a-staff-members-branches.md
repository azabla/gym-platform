# ADR 0025: Lists limited to a staff member's branches

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

Staff can work at all of a gym's branches or only some (ADR 0003). The dashboard (ADR 0014) and the reports (ADR 0024) already covered only a staff member's branches. But since ADR 0017, the lists in the API and the staff panel showed the whole gym: every member, membership, payment, check-in and payment link. A receptionist at one branch could browse another branch's takings and members.

Members are welcome at every branch (ADR 0006), though. A receptionist at Bole has to find a Main Branch member who walks in, check them in, sell them a membership or take their money. So hiding members strictly by branch would break the front desk.

## Decision

### What staff limited to some branches see

| Records | Limited by | Opening one from another branch |
|---|---|---|
| Members | Their home branch, in the list | Allowed: a **search** finds any member of the gym, and a member's page opens |
| Memberships | The member's home branch, in the gym-wide list | Allowed: a member's own memberships, from the member's page or `GET members/{id}/memberships`, and a membership's page open |
| Payments and refunds | The branch where they were taken | 404 |
| Check-ins | The branch where they were made | No single-record page |
| Payment links | Their branch | 404 |

- **The desk keeps working.** It finds a visitor by search, opens their page and their memberships, checks them in, sells to them and takes their money at its own branch.
- **What stays hidden.** It doesn't see payments, links or check-ins made at another branch, not even a visitor's.
- **Staff at all branches,** and owners always, see the whole gym as before, and can still narrow it to one branch.

### One rule, as scopes on the models

- **The scopes:**
  - `Member::homeAtAnyOf()`;
  - `Membership::ofMembersAtAnyOf()`, by the member's home branch, with archived members included;
  - `Payment::takenAtAnyOf()`, `PaymentLink::forAnyOf()` and `CheckIn::madeAtAnyOf()`, by the branch where they happened.

  Each takes `Staff::limitedToBranchIds()`, where null means every branch.
- **Who uses them:** the API's lists, the panel's tables, the reports and the dashboard. So a list, its totals, the dashboard and a downloaded file always agree.
- **Opening one record.** The payment and payment-link policies also need the staff member to work at the record's branch. Otherwise they answer `denyAsNotFound()`, so another branch's record looks like it isn't there, as another gym's does (ADR 0004). The requests that void, cancel or check one return the policy's answer, so the 404 survives.
- **`?branch_id=`.** On the payments and check-ins lists it must be a branch the staff member works at, through the same rule as the dashboard and reports (`AtTheStaffMembersBranches`).

### In the panel

- **Members:** the table is limited while its search is empty, and any member's page opens.
- **Memberships:** the table is limited unless it's filtered to one member, which is what the member page's link does.
- **Payments and check-ins:** limited in the resource's query, so a payment page of another branch is a 404.
- **Branch filters** offer only the staff member's branches.

### Deferred

- Plans and announcements. Their lists stay gym-wide, as before. Changing them is already limited (ADR 0023).
- A "current branch" setting, for staff at all branches who mostly work at one.

## Consequences

- A receptionist sees their own branch's money, members and visits, and can still serve any member of the gym.
- A member's page at another branch's desk shows their memberships and what they owe in total. The payments behind those totals stay with the branch that took them.
- Everything that lists or counts records goes through the same scopes, so a new list should use them too.
