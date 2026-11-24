# ADR 0039: Closure reminders and yearly holidays

- **Status:** Accepted
- **Date:** 2026-11-24

## Context

Closures (ADR 0037) told members only once, when added, and only if they started within 14 days. That left three gaps:
- **No reminder.** A holiday added a month ahead was never pushed.
- **No undo message.** Taking a closure back said nothing, so members who had been told stayed away from an open gym.
- **Re-added every year.** Each fixed holiday had to be added again yearly. Genna, Timket and Meskel fall on fixed Ethiopian dates, which move a day on the Gregorian calendar in some years: Timket on Tir 11 is 19 January in most years but 20 January in a Gregorian leap year.

## Decision

### An Ethiopian calendar

`App\Domains\Shared\EthiopianCalendar\EthiopianDate` converts dates to and from the Ethiopian calendar exactly, through day numbers. It is plain PHP with no package:
- 12 months of 30 days and Pagume, of 5 days or 6 in a year that leaves 3 when divided by 4;
- the same date a year later, with Pagume 6 becoming Pagume 5 when the year lacks it;
- month names, for display.

### Reminders the day before

- **When.** `notifications:send-closure-reminders` runs hourly. From 09:00 gym time, members of a branch closed tomorrow, or open other hours, get the same `branch_closed` or `branch_hours_changed` push and inbox message.
- **Once only.** Each closure is reminded once (`reminded_at`). There's no reminder if members were told in the last 2 days, which covers a closure added just before (`members_told_at`).

### Taking a closure back

- **The domain method.** `BranchClosure::takeBack()` deletes the closure. If its members had been told and it hasn't ended, it announces `BranchClosureTakenBack` with its days.
- **The message.** The branch's members get `branch_reopened`, "Main Branch at Demo Gym is open as usual on …", in English or Amharic. Nobody else hears anything.
- The API's `DELETE` and the panel's **Remove** both use it.

### Yearly holidays

- **Marking.** A closure can be marked **every year** (`repeats_yearly`, a toggle in **Add closure**).
- **Next year's copy.** Once a yearly closure ends, `branch-closures:repeat-yearly` (hourly) adds next year's copy, once (`repeated_at`):
  - on the same **Ethiopian** date, so Timket stays on Tir 11 whichever Gregorian day that is;
  - as many days long, with the same hours and reason;
  - itself repeating every year.
- **Overlaps.** A copy that would overlap another closure is skipped.
- **Telling members.** They hear of the copy through the day-before reminder.
- **Showing it.** The panel shows "Yearly, on Tir 11". The API's closures carry `repeats_yearly` and `ethiopian_date`.
- **Moving holidays.** Fasika and Eid are still added each year.

## Consequences

- **The scheduler matters more.** Two more hourly commands depend on it running, as membership reminders already do (ADR 0028's always-on server).
- **One copy at a time.** Next year's copy appears only once this year's has ended, so a holiday shows in the apps 60 days ahead only from then on.
- **Members app.** It should handle the new `branch_reopened` push type. Reminders reuse the two closure types.

### Deferred

- A built-in list of Ethiopia's public holidays, including the moving ones.
- Choosing how far ahead reminders go out.
