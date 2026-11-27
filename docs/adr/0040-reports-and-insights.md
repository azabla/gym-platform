# ADR 0040: Reports and insights

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

The dashboard (ADR 0014) shows a period's figures, and the CSV reports (ADR 0024) list records. Owners also wanted to see how the gym does month by month:
- what each plan brings in;
- whether members renew;
- when the gym is busiest;
- how many members it keeps and how fully visit packs are used.

Until now that meant downloading records and working it out in a spreadsheet.

## Decision

### Four insights (`App\Insights\GymInsights`)

Each covers the **last 3, 6 or 12 months** (12 by default) up to today, by the gym's own dates, at the branches chosen. Each runs a fixed number of grouped queries, however many members there are.

| Insight | What it gives | Branch by |
|---|---|---|
| Revenue by month | Money taken, net of refunds, voided payments left out, in all, by plan and by method | The payment's branch |
| Renewals and churn | Memberships that ended each month (not cancelled): **renewed** (the next one started within 14 days of the end), **lapsed**, or **waiting** (ended under 14 days ago); the renewal rate among those decided, by plan | The member's home branch |
| Busiest hours | Check-ins that let members in, in a weekday-by-hour grid where the gym is | The check-in's branch |
| Members and visit packs | For each month: members with a valid membership at its end, new members, and visit packs that ended with visits bought, used and left over | The member's home branch |

Archived members still count for the months they were members.

### Who sees them

`App\Insights\Insight` names the four. A `see-insight` ability decides who sees each, the same in the panel, the CSV files and the API:
- revenue goes to the roles that see the gym's money (owner, manager, accountant);
- the rest go to owners and managers.

Staff limited to some branches see only theirs (ADR 0025).

### Panel, CSV and API

- **Panel.** **Reports > Insights**, a dashboard-style page with a period and branch filter. Each insight is a chart, using Filament's built-in Chart.js with no new package, and its figures in a table beneath:
  - revenue as bars stacked by plan;
  - renewals as bars with the rate as a line;
  - busiest hours as a line per weekday, plus a grid shaded by how busy each hour is;
  - members as lines with visit-pack bars.

  None of these appear on the dashboard.
- **CSV.** Each table, and a card on the Reports page, downloads its insight. There is one row a month, or one row a weekday for busiest hours, with amounts as plain birr.
- **Staff API.** `GET organizations/{gym}/insights/{revenue|renewals|busy-hours|members}` returns JSON (money as the usual money objects). `GET organizations/{gym}/reports/insights/{…}` returns the CSV. Both take `?months=3|6|12` and `?branch_id=`.

## Consequences

- **The 14-day rule.** "Renewed" means a new membership within 14 days of the end, so a member who comes back after a month counts as lapsed then. The rule is one constant (`GymInsights::RENEWAL_DAYS`).
- **Members by month** runs one small count query per month for the active members: at most 12.
- **A future owners' app** can use the API as it is.

### Deferred

- Comparing a period with the same one a year earlier.
- Choosing any date range rather than 3, 6 or 12 months.
- Emailing a monthly summary to owners.
