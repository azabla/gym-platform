# ADR 0038: Offline scanning

- **Status:** Accepted
- **Date:** 2026-11-24

## Context

The door worked only online. `CheckIn::scan()` checks a GQ1 code (ADR 0011) with the credential's secret on the server, so when a gym's internet dropped, nobody could be let in by QR code. ADR 0011 deferred "offline scanning with signed member lists" (Volt's phase 7).

GQ1 already keys each code with a day key derived from the secret: `day_key = HMAC(secret, "GQ1-DAY|" + UTC day)`. A scanner holding day keys can check codes for those days without ever holding a secret.

## Decision

### The door list

`GET organizations/{gym}/door-list` gives any staff member who may scan what the staff app needs for **today and tomorrow (UTC)** at their branches:
- **Each active credential:** its `day_keys` for the two days (never the secret) and `last_used_step`.
- **Its member:** name and photo link.
- **For each of the gym's dates in that time:** what the membership allows (`allowed` or the reason), which branches, and visits left.
- **Each branch's hours** by date, from `Branch::windowOn()`, so late hours and closures (ADR 0037) carry over.

`App\Domains\Attendance\DoorList\DoorList` builds it in a fixed number of queries, whatever the number of members. It uses `AccessEvaluator::denialForStatus()`, the same rule the server applies, so the list, live scans and uploads can't drift apart.

**Two days** is the span. A stolen scanner exposes at most two days of day keys, and a sale, freeze or cancellation is at most two days stale. The app refreshes the list whenever online.

### Signing

- **Ed25519.** Each list is signed with PHP's built-in sodium (`DoorListSigner`), no new package. The answer carries the list as JSON text and the signature over those exact bytes. The app checks it with a public key it keeps, so a list edited on the device is refused.
- **The key.** It lives in `DOOR_LIST_SIGNING_KEY`. `php artisan door-list:generate-key` prints a new pair, and `door-list/public-key` serves the public key with a `key_id`.
- **Without a key** the list answers 503 with how to set it up.

### Checking offline

The app checks a scan as the server does, in ADR 0011's order:
1. the code's form and credential;
2. its step against the scanner's clock;
3. the HMAC from the day key;
4. the step against `last_used_step`;
5. the branch's hours;
6. the date's membership rules and visits;
7. the 5-minute rule.

### Sending the scans

`POST organizations/{gym}/check-ins/offline` takes up to 200 scans, each with the app's own id (kept as the idempotency key), the QR text, the branch, when it was scanned, and what the door decided.

- **Refused, not recorded:** the code doesn't match its time or isn't this gym's, the scan is dated over 2 minutes in the future or over 3 days ago, or it's at a branch the staff member doesn't work at. A scan can only be recorded with a real code for that moment, so nobody can invent check-ins.
- **Recorded:** what the door did, at `scanned_at` (kept in UTC), as an offline QR check-in by the staff member who sent it.
- **Re-decided.** The server then decides again for that moment. `AccessEvaluator::evaluate()` now takes a moment, and "today", hours, membership, visits and the 5-minute rule all follow it. It also checks a credential revoked by then, or the same code already recorded (`check_ins.code_step`).
- **Flagged.** When the server's answer differs, the check-in gets `server_disagrees` and `server_reason_code`. The door's decision stands, because that is what happened. Staff see **Needs review** in the panel's check-ins (with a filter) and a dashboard link.
- **Messages.** "One visit left" is still sent. "Check-in refused" is not, because the member was told at the door, maybe hours earlier.

## Consequences

- **Production needs `DOOR_LIST_SIGNING_KEY`**, and the staff app needs its public key. Changing the key means updating the app.
- **The staff app does more:** it keeps the list, checks codes and rules itself, stores scans until they are acknowledged, and handles `refused` and `server_disagrees` answers. The contract is in `docs/api/members-app.md`.
- **Stale lists.** A member whose membership was sold at the desk after the list was downloaded is turned away offline until the next download. Staff can still check them in at the desk once online.
- **Two scanners offline** can both let the same code in. The second is flagged `CODE_ALREADY_USED`, but the visit happened.

### Deferred

- Offline desk check-ins, without a QR code.
- Telling staff by push when an upload brings check-ins to review.
- Pushing a fresh door list when a membership changes.
