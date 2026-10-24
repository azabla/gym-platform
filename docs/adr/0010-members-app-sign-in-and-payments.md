# ADR 0010: The members app: SMS sign-in, seeing memberships, paying

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Members could pay online only through a link staff sent them (ADR 0009). The next piece is the members app, a Flutter app like the staff app (ADR 0004), where members:

- sign in with their phone;
- see their memberships, what they owe and their receipts;
- pay with Chapa themselves.

This ADR covers the API the app calls. Its screens come after, in their own project.

Each gym knows its members by phone number (ADR 0005), and many members don't use email. One person can be a member at several gyms on the platform.

## Decision

### Signing in with a code sent by SMS

- The member types their phone number (`POST auth/sms-codes`), gets a 6-digit code by SMS, and exchanges it for a token (`POST auth/sms-tokens`). As with staff sign-in, there is one token per device, revoked on sign-out.
- **The code:**
  - it works for 10 minutes and 5 tries; only the newest code works, and only once;
  - it is stored as a hash keyed with the app key, so the database alone doesn't reveal a code;
  - old codes are deleted daily (`model:prune`).
- **Limits:**
  - a code a minute and five an hour for each phone number;
  - 20 code requests an hour from each IP address;
  - five sign-in tries a minute.
- The first sign-in creates the user: the phone number, no email or password, and the member's name. Every user has an email or a phone number (a check constraint).

### Nobody can probe who is a member, or run up the SMS bill

- Only a number registered as a member at some gym gets a code, so asking for codes for random numbers costs nothing.
- Every number gets the same answer ("If this number is registered at a gym, a code is on its way by SMS."), and in the same time (Laravel's `Timebox`). So the endpoint doesn't reveal who is a member.
- The SMS is sent from the queue, so the answer never waits for the SMS provider either.

### Sending SMS: AfroMessage, behind an interface

- `SmsSender`, in a new Notifications domain, has two drivers, and `SMS_DRIVER` picks one:
  - `log` writes each message to the log. It is the default, for development and tests;
  - `afromessage` sends it through AfroMessage, an Ethiopian SMS gateway with an HTTP API.
- The platform has one AfroMessage account for all gyms, because a sign-in code belongs to a person, not to one gym.
- `SmsChannel` is a Laravel notification channel, so later messages (reminders, receipts) can go by SMS the same way.
- Production must set `SMS_DRIVER=afromessage`. With `log`, sign-in codes would sit in the log.
- AfroMessage's docs couldn't be reached while building this. The request follows AfroMessage's SDKs, and must be confirmed with the real account.

### The phone number links a user to their gyms

- **One app for all of a person's gyms:** `GET me/gyms` lists every gym where the user's phone number is registered as a member, leaving out archived members. Each gym comes with the membership at a glance and what is owed there.
- **The link is looked up on each request, not stored.** If staff correct a member's phone number, access moves with it.
- **The `member` middleware:**
  - under `me/gyms/{gym}/`, it finds the member record at that gym by the user's phone number, and acts as that gym, like the staff `organization` middleware (ADR 0004);
  - every query is limited to that member record;
  - an unknown gym, a gym where the user isn't a member, an archived member and another member's record all get the same 404.
- **Staff who are also members** get a separate app account for now, because their staff account has an email, not a phone. Linking the two can come later.

### What members see

- **Memberships:**
  - plan, status, dates and price;
  - what is paid and what is due;
  - the branches where it is valid;
  - freeze days allowed, used and left, and the freezes, with dates only;
  - the open payment link, if any.
- **Receipts:** number, date, amount, method, transaction number, branch, plan, and whether it was voided.
- **Internal notes stay out:** discount, cancellation and void reasons, and which staff member did what. The app has its own response shapes (`app/Http/Resources/V1/Me/`), so a field added for staff never reaches it.

### Paying in the app

- **"Pay"** (`POST me/gyms/{gym}/memberships/{id}/payment-link`):
  - returns the membership's open payment link, if staff already created one;
  - otherwise creates one for everything still due, for the member's home branch, with no staff member as its creator;
  - two taps at once still leave one open link.
- **After paying on Chapa's page,** the app asks the server to check with Chapa (`POST payment-links/{id}/check`), so it can show the payment straight away. The rest works as in ADR 0009: Chapa's word is always checked, and a payment is recorded once.
- **Part payments** stay with staff for now.
- **A stale link is refused.** If the open link asks for more than is now due (the member paid at the desk since), the app gets a message to contact the gym, because paying it would only need a refund.

### Deferred

- The Flutter app itself.
- Buying or renewing a membership in the app, and freezing from it.
- Messages in Amharic.
- Push notifications.
- Linking a staff account and a member account.
- Token expiry (ADR 0004).
- AfroMessage delivery reports.

## Consequences

- Members sign in with the phone number their gym already has, and have no password to forget.
- The platform pays for every sign-in SMS. The limits, and codes only for registered numbers, keep that cost to real members.
- Whoever has a member's phone sees that member's gym records. If staff type a wrong number, a member's memberships and receipts show to whoever owns that number, so numbers must be right. Internal notes are never shown either way.
- Sending codes needs the queue worker running, in production as in development (ADR 0002).
- The members app and the staff app share one API and its conventions. The members' side can grow (renewals, freezes) without touching the staff side.
