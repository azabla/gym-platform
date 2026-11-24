# ADR 0037: Holiday closures and late hours

- **Status:** Accepted
- **Date:** 2026-11-24

## Context

Opening hours (ADR 0016) were one window per weekday, closing after opening on the same day. Two things couldn't be said:
- **Open past midnight.** A gym open Friday 18:00–02:00 turned members away after midnight.
- **Closed on set days.** A branch closed for Genna, Timket, Meskel or Eid, or for repairs, or open only a morning that day, had no way to say so. The desk had to turn people away by hand, and members found out at a locked door.

## Decision

### Hours past midnight

- **The rule.** A closing time earlier than the opening time means the next day: Friday 18:00–02:00 stays open until 02:00 on Saturday. Only equal times are refused (a new check replaces `closes_after_opening`).
- **One window.** `App\Domains\Organizations\Hours\OpeningWindow` (opens, closes) answers whether a time falls in its own day and whether it falls in the next morning.
- **Checking.** `Branch::isOpenAt()` checks today's window, then the night before's.
- **Calendar days.** A check-in after midnight still belongs to its calendar day: it uses that day's visit of a visit pack, and that day's membership dates apply. Someone training across midnight uses two visits. That keeps visits, receipts and reports on plain dates.

### Closures

- **Table `branch_closures`.** A closure is a branch closed from `starts_on` to `ends_on` (both included) with a reason, either all day, or open other hours those days (`opens_at`, `closes_at`, which may run past midnight). It overrides the weekly hours: `Branch::windowOn($date)` takes a closure on that date first, then the weekday's hours, and gives "open all day" for a branch without hours. A closure also cuts short the night before's late hours.
- **One-off dates.** Closures don't repeat yearly. Moving holidays (Eid) change every year anyway, and the gym adds next year's when known.
- **The rules** (`BranchClosure::refusalToSchedule`, `BranchClosureRefused` as a 422):
  - a closure starts today at the earliest and lasts at most 31 days;
  - it never overlaps another closure of the same branch;
  - it covers only branches the staff member works at.
- **Who manages them.** Owners and managers of the branch, as for its hours (`BranchPolicy::update`).
- **Several branches at once.** Closing several branches together makes one closure per branch, so each can be taken back alone, and announces `BranchClosureScheduled`.
- **Check-ins** on a closed day are turned away with the existing `OUTSIDE_HOURS`. The apps already know it, so no new code is needed.

### Telling members

Members whose home branch closes, or opens other hours, within the next **14 days** get an inbox message and a push when the closure is added:
- the types are `branch_closed` and `branch_hours_changed`, under the Announcements topic;
- they come in English or Amharic;
- there is no SMS.

Closures further ahead are only shown in the app. Every branch lists its closures from today to 60 days ahead, in the staff API and the members app.

### Panel and API

- **Panel.**
  - **Gym setup > Closures** lists the closures of the staff member's branches, with filters for when (coming or ended), branch and start date.
  - **Add closure** takes several branches at once, all day or other hours, and previews who will be told. **Remove** takes a closure back.
  - The branches list shows each branch's next closure, and late hours as "(next day)".
- **Staff API.** `GET`, `POST` and `DELETE organizations/{gym}/branch-closures`, and `closures` on branches.

## Consequences

- **Members app.** It should show `closures` with each branch, show late hours as "(next day)", and open the branches screen on the two new push types.
- **Who isn't told.** Taking a closure back sends nothing, so members told about it may need an announcement. Closures added more than 14 days ahead are never pushed, only shown in the app.
- **Reverting the hours migration** fails while any hours run past midnight.

### Deferred

- Closures that repeat every year.
- A reminder push a day or two before a closure added long in advance.
- Counting a night's visit across midnight as one visit.
