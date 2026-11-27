# ADR 0043: Pausing a gym

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

The platform needs to stop a gym from working for a while, most often one that hasn't paid, without losing anything: its members, memberships and money must all be there when it comes back.

## Decision

### The pause

- **Pause and resume.** `Organization::suspend(User $by, string $reason)` and `resume(User $by, ?string $note)`, from the admin panel's **Pause gym** and **Resume gym** (ADR 0042). A reason is required; the note is optional.
- **History.** `gym_suspensions` keeps each pause: who, when, why, who resumed it, and their note. A partial unique index allows one open pause per gym.
- **Quick check.** `organizations.suspended_at` is the quick check: `isSuspended()`, and the `active()` scope for gyms that aren't paused.
- **Refusals.** A double pause or resume is refused (`GymSuspensionRefused`).

### What a pause stops

| Where | How |
|---|---|
| Staff panel | Sign-in is refused with the message when every gym the person works at is paused. Someone who also works at an active gym lands there. A paused gym's pages answer 403 with a page saying so, in the staff member's language, with links to their other gyms. |
| Staff API (the desk, the door, the door list, offline uploads) | `ActAsOrganization` answers the gym's own staff `403 {"message": "…", "code": "GYM_PAUSED"}`. Anyone else still gets 404, so a pause can't be probed. `/me` marks each gym `paused`. |
| Members app | The gym's routes answer its members `403` `GYM_PAUSED`, with a message to ask at the gym. Non-members still get 404. `/me/gyms` keeps the gym, marked `paused`. A member's own photo and language, which aren't a gym's routes, keep working. |
| Scheduled messages | Membership reminders, the staff morning list and closure reminders skip paused gyms. A paused gym's due announcements wait, even one already queued, and go out once it is resumed. |
| Messages already queued | `HeldWhileTheGymIsPaused::shouldSend()` drops a message to a paused gym's member or staff when its turn comes. |

The message is translated (`GymSuspended`): staff are told to contact the platform's admins, and members to ask at the gym.

### What keeps running

- Checking open Chapa payment links, and recording what Chapa already took (no message is sent).
- Repeating yearly closures.
- Password links, which belong to the person, not the gym.

## Consequences

- **Resume restores everything.** Nothing is deleted or changed by a pause beyond the pause itself.
- **Late announcements.** Announcements that came due during a pause go out late, right after resuming. Staff can cancel them first if they no longer apply.
- **Expiry while paused.** Memberships keep their dates while the gym is paused. Members whose membership ended in that time get no reminder for it.
- **The apps** show a screen for `GYM_PAUSED`. The staff app keeps offline scans stored until the gym is resumed, then uploads them.

### Deferred

- Pausing automatically for an unpaid platform bill, with a warning first.
- Extending memberships by the length of a pause.
- Telling a paused gym's members by SMS.
