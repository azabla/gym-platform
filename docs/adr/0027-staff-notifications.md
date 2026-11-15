# ADR 0027: Staff notifications

- **Status:** Accepted
- **Date:** 2026-11-15

## Context

Members hear about their memberships, payments and visits (ADR 0012), but staff heard about nothing. They had to look:
- A member paying online through Chapa (ADR 0009, ADR 0013) showed up only in the payments list.
- A link Chapa paid when nothing was due any more was marked refund needed, but nobody was told to refund it.
- A member freezing in the app (ADR 0026) changed a membership's end date without the desk knowing.
- The members to call were on the dashboard (ADR 0014) only for whoever opened it.

## Decision

### What staff hear about

| Message | When | Who | Opens |
|---|---|---|---|
| Paid online | A Chapa payment is recorded: a link paid, or a plan bought in the app | Owners, managers and accountants who work at the payment's branch | The payment |
| Refund needed | Chapa took money that isn't due any more (`PaymentLinkNeedsRefund`, new) | The same, at the link's branch | The member |
| Frozen in the app | A member freezes their membership in the app | Owners, managers and receptionists who work at the member's home branch | The membership |
| Back early | A member ends a freeze early in the app (`MembershipFreezeEnded`, new) | The same | The membership |
| Members to call | Once a day from 08:00 where the gym is, when there are any | Owners, managers and receptionists, counted for their branches | The dashboard |

- **What happens at the desk tells no one.** A payment taken, a freeze made or ended there was done by staff, who know.
- **Trainers hear nothing,** and archived staff are left out.
- **`App\Notifications\StaffRecipients` decides who receives each message,** through the same branch scopes as the lists (ADR 0025).
- **The morning list:**
  - `notifications:send-staff-morning-list` runs every hour;
  - it counts `StaffDashboard::expiring()` for each staff member's branches, so it agrees with the dashboard;
  - it sends nothing when the count is zero, and never twice in a day.

### One inbox per staff record

- **Where messages live.** Messages go to the staff record, not the user. They're kept in the `notifications` table beside the members' (morph alias `staff`), so someone working at two gyms reads each gym's messages apart, as a member does. No new tables.
- **How a message is stored and written.** `StaffNotification` keeps what a message is written from and what it opens, as member messages do. The text is written when read, from `lang/en/staff_notifications.php`.
- **Names are allowed.** Staff messages don't show on a lock screen, so unlike member messages they name the member.
- **Where messages are made.** The domains announce events, and `App\Listeners\TellTheStaff` turns them into messages at the event's gym, beside `TellTheMember`. Billing gains `PaymentLinkNeedsRefund`. Memberships gains `MembershipFreezeEnded`, and `endFreeze()` takes the staff member who ended a freeze, or none for the member.

### Where staff read them

- **Panel:** an **Inbox** page, with the unread count in the menu. **Open** marks a message read and goes to what it is about; **Mark all read** clears them.
- **API**, for a staff app later:
  - `GET organizations/{gym}/notifications` lists messages, newest first, with `meta.unread_count`;
  - `POST .../notifications/{id}/read` and `POST .../notifications/read-all` mark them read.

### Deferred

- Push and SMS to staff, which need staff push tokens.
- Amharic staff messages.
- More events, such as members turned away at the door or staff added and archived.
- Choosing which messages to receive.

## Consequences

- The desk and the owner hear about online money, refunds to make and freezes made in the app as they happen, and start the day with the number of members to call.
- A new staff message is a `StaffNotification` class, its text in the language file, and a handler in `TellTheStaff`.
- Messages build up in the `notifications` table; pruning old read ones can come later, for members and staff alike.
