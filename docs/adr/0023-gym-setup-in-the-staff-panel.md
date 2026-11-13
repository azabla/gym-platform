# ADR 0023: Setting up a gym in the staff panel

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

An owner invited with `gyms:create` (ADR 0019) could sign in to the staff panel, manage staff and work the front desk (ADR 0018). But plans, opening hours and announcements could only be changed through the API, from the app, and branches couldn't be added or renamed at all: only `gyms:create` and the seeder made them. A new gym couldn't sell anything until someone set up its plans in the app.

Two gaps showed up along the way:
- A manager limited to some branches could change or archive a plan sold at every branch, and limit a plan to branches they don't work at.
- Selling in the panel listed every plan, even one sold only at another branch.

## Decision

### The rules stay in one place, as in ADR 0018

- **Plans:**
  - `Plan` holds its limits: `MOST_MONTHS` (36), `MOST_DAYS` (366), `MOST_FREEZE_DAYS` (366) and `MOST_VISITS` (1,000);
  - `Plan::nameTakenBy()` checks the name, unique in the gym whatever the capitals; archived plans free theirs.
- **Branches:** `Branch::tidyCode()` and `codeTakenBy()` handle codes: 2 to 20 letters, digits or hyphens, stored in capitals, used once in a gym.
- **Announcements:** `Announcement::refusalToPost()` refuses a set time that isn't in the future, and a staff member limited to some branches speaking to the whole gym or to a branch that isn't theirs. It returns `AnnouncementRefused`, which the API answers with a 422.
- **Both sides call these:** the API's requests and the panel.

### Plan rights

- **Coverage.** Owners and managers manage plans as before, but only plans valid at branches they all work at (`Staff::worksAtEach()`, in `PlanPolicy`). So a manager limited to Bole manages the plans sold only at Bole, not those sold everywhere.
- **New plans and branch changes.** Such a manager chooses among their own branches when creating or changing a plan, and can't make one valid at every branch.
- **The API too.** This changes the API: those calls now answer 403 or 422 where they used to succeed.

### Branches: add and rename, no archive yet

- **API:** `POST organizations/{gym}/branches` (owners) and `PATCH branches/{branch}` (owners and managers who work at the branch) change the name, code and address. Scramble documents them (ADR 0021).
- **A new branch** is always open until it has hours (ADR 0016).
- **Why no archive yet:** members have a home branch, and payments, check-ins, staff and plans point at branches. Closing one needs its own rules about where all of those go.

### What the panel does

| Page | What | Who (the policies decide) |
|---|---|---|
| Plans | List; add, change, archive | Everyone sees them; owners and managers change those they cover |
| Branches | List of the branches the staff member works at, with hours; add | Add for owners |
| A branch | Name, code, address and the whole week of opening hours | Owners and managers who work there |
| Announcements | List with status, time and recipients; post, change and cancel while scheduled | Everyone sees them; owners and managers post; staff limited to some branches speak to one of theirs |

- **Plans:** a plan's branches are a choice among the staff member's own; empty means every branch.
- **Opening hours:** each weekday once, closing after opening on the same day, in the gym's time zone. An empty week is always open.
- **Announcements:**
  - one sent now is queued at once, as in the API, and the page says how many members it goes to;
  - a set time is chosen in the gym's time zone and kept in UTC.
- **Selling** offers only the plans on sale at the member's home branch (`Plan::onSaleAt`), as the members app does.

### Deferred

- Archiving or closing a branch.
- Opening hours past midnight, and holiday closures (ADR 0016).
- Restoring an archived plan.
- Payment links from the panel (ADR 0018).

## Consequences

- A gym can be set up entirely in the browser, from the owner's first sign-in: branches, hours, plans, staff, then selling.
- The rules for plans, branches and announcements are written once, so the API and the panel can't drift apart.
- Branch-limited managers lose rights they had over plans sold everywhere. A gym that relied on that needs an owner or a manager at all branches to change those plans.
- Staff limited to some branches still see the whole gym's plans, and every staff member sees every announcement, as in the API.
