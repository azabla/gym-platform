# ADR 0044: Importing members and money brought in

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

A gym that joins the platform already has members, often hundreds, kept in a spreadsheet or a notebook, many in the middle of a paid membership. Typing them in one by one at the desk would take days, and selling them a membership again would count money taken long ago as this month's.

## Decision

### Money brought in

- **A new method.** `PaymentMethod::BroughtIn` (`brought_in`, "Brought in", Amharic ከቀድሞ መዝገብ) is money a member paid before their gym joined. It has no transaction number.
- **Never chosen by hand.** It isn't offered at the desk (`PaymentMethod::forTheDesk()`), and the API refuses it. Only an import records it.
- **It counts towards balances,** so a member who paid 1,000 of 2,500 owes 1,500.
- **It never counts as money taken.** The `Payment::moneyTaken()` scope and `PaymentMethod::moneyTaken()` leave it out of:
  - takings and the dashboard;
  - revenue insights and their CSV;
  - the admin panel's figures;
  - the payments report, unless asked for that method.

  `Payment::totalsOf()` totals it apart, as `brought_in`, and the payments list shows it as a note.
- **No message.** Recording it sends the member no payment message.

### Bringing in in the domain

- **Membership.** `Membership::bringIn()` records a membership at the plan's price, from a past day if need be, to a given end or the plan's length. Its seller is the staff member who ran the import.
- **Payment.** `Payment::bringIn()` records what was paid, as brought in, on the membership's first day.
- **Shared checks.** Selling and bringing in share one clash check (`refusalToCover()`), and a membership can't end before its first day.

### Importing a file

A new `Imports` domain may use Members, Memberships and Billing together, and no other domain depends on it.

1. **The file.** Owners and managers download the template and fill it in:
   - **format:** CSV saved as UTF-8 (a byte-order mark is fine), with commas or semicolons; no package was added;
   - **columns:** `full_name`, `phone` and `home_branch` (a name or code) are required; `gender`, `date_of_birth`, `plan`, `starts_on`, `ends_on` and `amount_paid` are optional; header names match however they are capitalised or spaced;
   - **size:** up to 2,000 rows and 1 MB.
2. **The check.** `MemberImport::check()` keeps each row and what checking it gave: ready, an error with its reason, or a duplicate.
   - **Phones:** typed the Ethiopian ways. A leading zero a spreadsheet dropped is fine; a phone turned into `2.51911E+11` is refused with how to fix it.
   - **Branch and plan:** the branch must exist, and the plan must be on sale at it.
   - **Membership:** it needs its plan and first day, can't end before it starts, and the amount paid can't be more than the price.
   - **Dates:** `2026-11-27` or `27/11/2026`. The preview shows them in Ethiopian and Gregorian, so a date the spreadsheet changed is seen.
   - **Duplicates:** a phone already at the gym, archived members included ("restore them instead"), or earlier in the file, is skipped and reported. Importing the same file again changes nothing.

   Reasons are kept as English text and values, and shown in each reader's language.
3. **The import.** Staff look over the rows, then **Import N members** or **Discard**.
   - **The job.** `ImportMembers` runs `MemberImporter`, 100 rows at a time: each run stays well within the worker's 90-second limit and lets SMS jobs run in between.
   - **One row per transaction.** Each row is checked again first, as the gym may have changed. One that fails doesn't undo the others. The gym's receipt counter, which an all-at-once import would hold for the whole run, is never held for long.
   - **Nobody is told.**
4. **Afterwards.** The rows not imported download as a CSV, with why in a last column, to fix and upload again.
5. **Pruning.** The rows, with their names and phones, are deleted 30 days after an import finishes; the import's summary stays.

## Consequences

- **A gym can start in a day** with its members, their memberships and what they owe.
- **Reminders.** Imported memberships ending soon get the usual reminders from the next hourly run, which is wanted.
- **Excel quirks.** Excel reformats dates by the computer's region and long phones as numbers. The preview and the reasons show these, but staff still fix them in the file.
- **Receipt numbers.** Brought-in payments use the gym's receipt numbers, as they are payments on its records.

### Deferred

- Reading `.xlsx` directly (it needs a package).
- Ethiopian-calendar dates in the file.
- Importing staff, plans or past check-ins.
- Restoring archived members from the file.
