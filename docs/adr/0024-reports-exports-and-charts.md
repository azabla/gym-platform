# ADR 0024: Reports, exports and charts over time

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

Owners and accountants keep the gym's books outside the platform, but nothing could be downloaded: there was no CSV, no export and no streamed file anywhere. The dashboard (ADR 0014) adds up a period, but gave no figures day by day; ADR 0014 put charts over time and exports off for later.

## Decision

### Four reports as CSV files

| Report | What | Who | Branches by |
|---|---|---|---|
| Payments | Every payment and refund paid in the period, oldest first; refunds negative, voided ones marked | Owners, managers, accountants | The payment's branch |
| Memberships | The memberships sold in the period (as the dashboard counts them), with price, discount, paid and owed | Owners, managers, accountants | The member's home branch |
| Check-ins | Every check-in in the period, with the time where the gym is, let in or turned away and why | Every staff member | The check-in's branch |
| Members | The members as they are today, with their membership at a glance and balance | Owners and managers | The member's home branch |

- **Who:**
  - A new `export` ability on each model's policy decides.
  - Money stays with the roles that see it (`StaffRole::seesMoney`, ADR 0014).
  - The members report holds everyone's phone number, so only owners and managers get it.
- **The period and branches** are read exactly as the dashboard reads them, through one request concern, `CoversAPeriodAtBranches`:
  - the period is today unless chosen, and at most a year;
  - the branch is one the staff member works at, or else all of theirs.
- **API:** `GET organizations/{gym}/reports/{payments|memberships|check-ins|members}`, with the payments list's `method` and `member_id` filters, `result` for check-ins and `search` for members. Scramble documents the routes (ADR 0021).
- **Panel:** each list has a **Download CSV** action with the period and branch. It produces the same file as the API.

### One set of report classes, streamed

- **Where:** `App\Reports`, beside `App\Dashboards` and like it outside the domains. Each report reads several of them, and an architecture test keeps it independent of the HTTP layer and the panel.
- **The classes:**
  - `ReportPeriod` holds the gym, the days and the branches;
  - each report gives its headings and yields its rows;
  - `Csv::download()` streams them.
- **Memory:** records are read in chunks of 500, and balances are worked out per chunk, so a year of a busy gym never sits in memory.
- **Why not Filament's exports:** they need a queue, an exports table and database notifications for staff, and they work only in the panel. A streamed download works the same from the API and the panel and needs none of these.
- **The file:**
  - UTF-8 with a byte order mark, so spreadsheets read Amharic names;
  - money as decimals with a currency column;
  - dates as `Y-m-d`, and times in the gym's time zone;
  - labels as the panel shows them. Payment methods, check-in methods and denial reasons now name themselves on their enums.
  - A cell that a spreadsheet would run as a formula (starting with `=`, `@`, or `+`/`-` not followed by a digit) is written as text. Phone numbers and negative amounts stay as they are.

### Charts over time

- **`StaffDashboard::trends()`** gives the period day by day:
  - `takings_by_day`: net, refunds taken off and voids left out, for the roles that see money;
  - `check_ins_by_day`: let in and turned away, by the gym's day;
  - `check_ins_by_hour`: the 24 hours of the gym's day.

  Every day appears, with zeros on quiet ones. Days and hours come from PostgreSQL's `AT TIME ZONE`, in the gym's time zone.
- **API:** the staff dashboard (`GET dashboard`) returns them as `trends`. The members app's dashboard is unchanged.
- **Panel:** three charts on the dashboard follow its period and branch filters:
  - **Taken each day**, for the money roles only;
  - **Check-ins each day**;
  - **Busy hours**.

### Deferred

- Comparing a period with the one before it (ADR 0014).
- Excel files, PDFs, and reports sent by email on a schedule.
- Searching or filtering in the panel's download beyond the period and branch. The API's report filters (method, member, result, search) aren't in the panel yet.
- Limiting the lists themselves to a staff member's branches. The reports and the dashboard are limited; the lists still show the whole gym (ADR 0017).

## Consequences

- A gym can hand its accountant a month's payments, memberships and check-ins in seconds, without asking us.
- The dashboard shows how the period went day by day, and when members come in.
- Reports read the same records as the lists and the dashboard, so the figures agree.
- A report is computed on each download. For a year at a large gym that is a few seconds of queries, streamed as they run, so nothing waits on a queue.
