# ADR 0011: Check-ins and rotating QR codes

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Members can sign in to the members app, see their memberships and pay (ADR 0010). The next piece is attendance: a member shows a QR code at the door, staff scan it, and the system lets them in or says why not.

The members app's team wrote a contract for the QR code, "Volt: Rotating QR Credential & API Contract". It defines the GQ1 algorithm, with test vectors, the checks a scan goes through, and reason codes for a denial. Where Volt and this API differ (URLs, IDs, endpoint names), the app follows the API. The contract for the app is `docs/api/members-app.md`.

A plain QR code holding the member's ID could be photographed and shared. The code has to change, work only once, and still be made on a phone that may be offline at the door.

## Decision

### A QR credential for each member's phone

- **Issuing:** the members app asks for a credential for a gym (`POST me/gyms/{gym}/access-credential`) and gets a 32-byte secret once. From then on the phone makes codes offline.
- **The secret is stored encrypted** with the app key (Laravel's `encrypted` cast), not hashed, because the server makes the same codes to check a scan. It is never shown again.
- **Credentials have ULIDs,** unlike everything else, because GQ1 puts the credential's ID in the code. Everything else keeps numeric IDs.
- **One phone per member:** issuing a credential revokes the member's previous one (`replaced`). A partial unique index keeps one active credential per member, and the member's row is locked while issuing.
- **Signing out** on the phone revokes the credential issued under that sign-in token (`signed_out`).
- **An expired membership doesn't revoke the credential.** The scan is denied instead, with the reason, and the same credential works again after a renewal.

### GQ1: a code that changes every 30 seconds

- `step = floor(unix time / 30)`.
- `day_key = HMAC-SHA256(secret, "GQ1-DAY|" + floor(step / 2880))`, a key for each UTC day.
- `code = the first 8 bytes of HMAC-SHA256(day_key, "GQ1|" + credential ULID + "|" + step)`, as 16 uppercase hex characters.
- The QR code holds `GQ1:<credential ULID>:<step>:<code>`.
- `App\Domains\Attendance\Credentials\Gq1` implements it, and Volt's test vectors are its unit test.

### Checking a scan

In Volt's order, the first failure decides:

1. The text reads as a GQ1 code; otherwise `CREDENTIAL_INVALID`.
2. Its credential belongs to this gym; otherwise `CREDENTIAL_INVALID`, without saying that it exists at another gym.
3. The credential isn't revoked; otherwise `CREDENTIAL_REVOKED`.
4. Its step is within one step (30 seconds) of now, either way, for a phone clock a little off; otherwise `CODE_EXPIRED`.
5. The code matches, compared in constant time; otherwise `CREDENTIAL_INVALID`.
6. The step is later than the last step used; otherwise `CODE_ALREADY_USED`. One conditional update checks and moves it, so two scans of one screenshot can't both pass.
7. The membership rules below.

A screenshot sent to a friend therefore works for about a minute, and only once.

### The membership rules

`AccessEvaluator` decides for QR scans and desk check-ins alike, with the membership that best shows where the member stands today (`Membership::atAGlanceFor()`):

- none, one not started yet, or a cancelled one → `MEMBERSHIP_NONE`;
- expired → `MEMBERSHIP_EXPIRED`;
- frozen today → `MEMBERSHIP_FROZEN`;
- not valid at this branch → `BRANCH_NOT_ALLOWED`;
- let in during the last 5 minutes → `ALREADY_CHECKED_IN`, so one membership can't let a second person in straight after.

**Money owed doesn't stop anyone.** The answer shows the balance due, so the desk can ask for it. Opening hours and visit packs wait, because plans have neither yet.

### Every attempt is recorded

- A check-in keeps the member, branch, membership, credential, method (`qr` or `desk`), result (`allowed` or `denied`), reason, staff member and time. A check constraint keeps a reason on every denial and none on an allowed check-in.
- A scan of a code we can't read, or of an unknown credential, records no member.
- **Desk check-in** (`POST members/{member}/check-ins`) is for a member who forgot their phone. The same rules apply.

### At the door

- **Every staff role can scan,** trainers included, because anyone may work the door. The branch is one the staff member works at, and their only branch by default, as for payments.
- **A scan always answers 200,** with `decision` set to `allow` or `deny` and the reason code, because a denial is a normal answer at the door, not an error.
- **An optional `Idempotency-Key` header** gives a retried scan the first answer back, so a scanner that lost a response doesn't trip the replay check.
- **The list** (`GET check-ins`) shows the gym's check-ins, newest first, filtered by day in the gym's time zone, branch, result or member.
- **Members see their own visits** (`GET me/gyms/{gym}/check-ins`), with the reason when turned away, but not which staff member was at the door.

### Deferred

- Offline scanning with signed member lists (Volt's phase 7).
- Opening hours and visit packs.
- Member photos at the door.
- Staff revoking a credential by hand, and a list of a member's devices.

## Consequences

- A shared screenshot is worth little: it expires in about a minute and works once.
- The phone makes codes without the internet, but the scanner needs it.
- Credential secrets depend on the app key. Rotating `APP_KEY` without `APP_PREVIOUS_KEYS` makes every credential unreadable, and members must issue new ones.
- A phone clock more than about a minute off makes every code `CODE_EXPIRED`. The app should use the server's time (the `Date` header) if the phone's is wrong.
- Every scan writes a row, denied ones included, so the table grows with traffic; it is indexed for the gym's newest-first list.
- Members, Memberships and Billing don't know about Attendance (an architecture test), so check-ins can change without touching them.
