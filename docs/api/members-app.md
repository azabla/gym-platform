# Members app API

The contract between the backend and the members app (Volt). Where this file and the Volt doc disagree, this file wins. Decisions behind it: ADR 0010 (sign-in, memberships, paying), ADR 0011 (check-ins and QR codes) and ADR 0012 (notifications).

## Conventions

- **Base URL:** `/api/v1`. Send `Accept: application/json` on every request, and `Authorization: Bearer <token>` once signed in.
- **The gym is in the path,** by its slug: `/me/gyms/{gym}/…`, for example `/me/gyms/demo-gym/memberships`. There is no gym header.
- **IDs are numbers,** except a QR credential's `credential_id`, which is a ULID because GQ1 puts it in the code.
- **Responses** wrap their body in `data`. Lists are paginated, 25 a page, with Laravel's `links` and `meta` (`?page=2`).
- **Money** is an object: `{"amount_minor": 150000, "currency": "ETB", "formatted": "1,500.00 ETB"}`. Show `formatted`, compute with `amount_minor`.
- **Dates** are `YYYY-MM-DD`, in the gym's time zone. **Times** are ISO 8601 with an offset.
- **Phone numbers** can be typed any common Ethiopian way (`0922 34 56 78`, `+251922345678`); they come back as `+251922345678`.
- **Errors:**
  - `401` not signed in, or the token was revoked;
  - `404` for anything the member can't see: an unknown gym, a gym where they aren't a member, or another member's record. The app can't tell these apart, on purpose;
  - `422` with `message` and `errors` per field;
  - `429` too many requests, with `Retry-After`.

## Signing in

| Request | Answer |
|---|---|
| `POST /auth/sms-codes` `{"phone": "0922 34 56 78"}` | `202` `{"message": "If this number is registered at a gym, a code is on its way by SMS."}`, the same for every number |
| `POST /auth/sms-tokens` `{"phone": "0922 34 56 78", "code": "123456", "device_name": "Tigist's Pixel 7"}` | `201` `{"data": {"token": "…", "user": {"id", "name", "email", "phone"}}}`; `422` on `code` if wrong or expired |
| `DELETE /auth/tokens/current` | `204`. Signs out this phone and revokes its QR credential. |

- A code is 6 digits, works for 10 minutes and 5 tries, and only the newest one works.
- Limits: a code a minute and five an hour for each number; five sign-in tries a minute.
- There is no self-registration: staff register members at the gym, and the member signs in with that phone number.
- One token per phone. Keep it in secure storage.

## Gyms and memberships

| Request | Answer |
|---|---|
| `GET /me/gyms` | Every gym where the phone number is a member: `slug`, `name`, `member` (`id`, `full_name`, `home_branch`), `membership` at a glance (`id`, `plan`, `status`, `starts_on`, `ends_on`) or `null`, and `balance_due` there |
| `GET /me/gyms/{gym}/memberships` | The member's memberships, newest first |
| `GET /me/gyms/{gym}/memberships/{id}` | One membership |
| `GET /me/gyms/{gym}/payments` | Receipts, newest first |
| `GET /me/gyms/{gym}/payments/{id}` | One receipt |

**A membership:** `id`, `plan` (`id`, `name`), `status` (`upcoming`, `active`, `frozen`, `expired` or `cancelled`), `starts_on`, `ends_on` (both included), `price`, `amount_paid`, `balance_due`, `valid_at_all_branches`, `branches`, `freeze_days_allowed`, `freeze_days_used`, `freeze_days_left`, `freezes` (`starts_on`, `ends_on`, `days`), `cancelled_at`, `open_payment_link`.

**A receipt:** `id`, `receipt_number`, `type` (`payment` or `refund`), `amount`, `method` (`cash`, `telebirr`, `cbe_birr`, `bank_transfer` or `chapa`), `reference`, `paid_on`, `branch`, `membership` (`id`, `plan`), `voided`.

**A branch:** `id`, `code`, `name`, `address`.

## Paying

| Request | Answer |
|---|---|
| `POST /me/gyms/{gym}/memberships/{id}/payment-link` | The open link for what is due: `200` if staff already opened one, `201` if created. `id`, `status`, `amount`, `checkout_url`, `membership_id`, `created_at`, `settled_at`, `payment`. `422` when the gym doesn't take online payments yet, nothing is due, or the open link asks for more than is now due (the member is told to ask the gym). |
| `GET /me/gyms/{gym}/payment-links/{id}` | The link as it stands |
| `POST /me/gyms/{gym}/payment-links/{id}/check` | Asks Chapa now, after the member comes back from Chapa's page. Ten a minute. |

Open `checkout_url` in a browser tab. A link's `status` is `pending`, `paid`, `cancelled` or `refund_needed`; once `paid`, `payment` has the receipt number.

## The QR code

### The credential

| Request | Answer |
|---|---|
| `POST /me/gyms/{gym}/access-credential` | `201` `{"data": {"credential_id": "01K7Z3M6Q8V2N4R5T9W1X0Y2AB", "algorithm": "GQ1", "status": "active", "revoked_reason": null, "issued_at": "…", "secret": "<base64 of 32 bytes>"}}`. Ten a minute. |
| `GET /me/gyms/{gym}/access-credential` | This phone's latest credential for the gym, without `secret`: `status` `active`, or `revoked` with `revoked_reason` (`replaced` or `signed_out`). `404` when this phone has none. |

- **The secret is shown once.** Keep it in secure storage, for each gym. If it's lost, issue a new credential.
- **One phone per member:** issuing a credential revokes the member's previous one, on any phone (`replaced`). The app should call `GET` when it opens, and issue a new one when it finds its credential revoked.
- **Signing out** revokes the credential issued under that sign-in (`signed_out`).
- An expired membership doesn't revoke the credential; the scan is denied with a reason instead.

### GQ1

```
step    = floor(unix_seconds / 30)
day_key = HMAC-SHA256(secret, "GQ1-DAY|" + floor(step / 2880))
code    = uppercase hex of the first 8 bytes of HMAC-SHA256(day_key, "GQ1|" + credential_id + "|" + step)
payload = "GQ1:" + credential_id + ":" + step + ":" + code
```

- `secret` is the 32 decoded bytes, not the base64 text. `credential_id` is the uppercase ULID, as given. Numbers are decimal, with no padding.
- Show `payload` as the QR code, and make a new one every 30 seconds.
- The test vectors in the Volt doc pass against the backend (`tests/Unit/Domains/Attendance/Credentials/Gq1Test.php`).
- The server accepts the step before and after its own, so a phone clock may be off by about 30 seconds. If the phone's clock is far off, use the server's time from the `Date` header of any response.

## Visits

`GET /me/gyms/{gym}/check-ins` lists the member's visits, newest first: `id`, `decision` (`allow` or `deny`), `reason_code` (below, or `null` when let in), `method` (`qr` or `desk`), `checked_in_at` and `branch`.

## Notifications

### Push token

| Request | Answer |
|---|---|
| `PUT /me/push-token` `{"provider": "fcm", "token": "…", "locale": "am"}` | `204`. Keeps this phone's FCM token and language. `provider` is optional and must be `fcm`; `locale` is optional, like `am` or `en-US`. |
| `DELETE /me/push-token` | `204`. This phone gets no more pushes. |

- Send it after signing in, whenever FCM replaces the token, and when the member changes language.
- Signing out (`DELETE /auth/tokens/current`) deletes it too.

### Pushes

A push has a `title` and `body`, safe for a lock screen, and `data`, every value a string:

| `type` | When | Other `data` | Open |
|---|---|---|---|
| `membership_expiring` | 5, 3 and 1 days before a membership ends | `membership_id` | that membership |
| `membership_expired` | the day after it ends | `membership_id` | that membership |
| `membership_frozen` | the gym freezes it | `membership_id` | that membership |
| `payment_succeeded` | a payment is recorded | `payment_id`, `membership_id` | that receipt |
| `visit_denied` | a check-in is turned away | `check_in_id`, `reason_code` | visits |

Every push also has `notification_id` (mark it read when tapped) and `organization_id`, to switch to the right gym first.

### Inbox

| Request | Answer |
|---|---|
| `GET /me/gyms/{gym}/notifications` | Newest first, 25 a page: `id`, `type`, `title`, `body`, `data` (as in the push), `read_at`, `created_at`, and `meta.unread_count` across every page |
| `POST /me/gyms/{gym}/notifications/{id}/read` | `204`, safe to repeat |
| `POST /me/gyms/{gym}/notifications/read-all` | `204`, this gym's only |

`title` and `body` are written in the request's `Accept-Language` (`en` for now; `am` once written), so send it.

### Preferences

`GET /me/gyms/{gym}/notification-preferences` returns `{"data": {"membership": true, "payments": true, "visits": true}}`. `PATCH` with any of those keys, each `true` or `false`, returns all three. An unknown key or another value gets a `422`. They decide pushes only: the inbox gets everything.

## At the door (staff app)

The scanner is the staff app, signed in as staff (ADR 0004), under `/organizations/{gym}/`:

| Request | Answer |
|---|---|
| `POST check-ins/scan` `{"payload": "GQ1:…", "branch_id": 1}` | Always `200` when the request is valid: `id`, `decision`, `reason_code`, `method`, `checked_in_at`, `branch`, `member` (`id`, `full_name`, `phone`) or `null`, `membership` (`id`, `plan`, `starts_on`, `ends_on`, `balance_due`) or `null`, `checked_in_by` (`id`, `name`) |
| `POST members/{id}/check-ins` `{"branch_id": 1}` | A desk check-in, for a member without their phone. The same answer and rules. |
| `GET check-ins` | The gym's check-ins, newest first. Filters: `?on=YYYY-MM-DD`, `?branch_id=`, `?result=allowed` or `denied`, `?member_id=` |

- `branch_id` can be left out by staff who work at only one branch.
- Send an `Idempotency-Key` header (any text up to 100 characters, new for each scan) so a retry after a lost answer gets the first answer back, instead of `CODE_ALREADY_USED`.
- Money owed doesn't stop entry: show `membership.balance_due` so the desk can ask for it.

### Reason codes

Checked in this order; the first that applies is the answer.

| Code | Meaning |
|---|---|
| `CREDENTIAL_INVALID` | Not a GQ1 code, an unknown credential or one of another gym, or a code that doesn't match. No member is shown. |
| `CREDENTIAL_REVOKED` | The credential was replaced by another phone, or its phone signed out |
| `CODE_EXPIRED` | The code is more than one 30-second step away from the server's time |
| `CODE_ALREADY_USED` | This code, or a later one, was already scanned |
| `MEMBERSHIP_NONE` | No membership today: none, not started yet, or cancelled |
| `MEMBERSHIP_EXPIRED` | The membership ended |
| `MEMBERSHIP_FROZEN` | The membership is frozen today |
| `BRANCH_NOT_ALLOWED` | The membership isn't valid at this branch |
| `ALREADY_CHECKED_IN` | The member was let in less than 5 minutes ago |

## Not built yet

- Offline scanning (Volt's phase 7).
- Member photos.
- Buying, renewing or freezing a membership in the app.
- Announcements from the gym.
- Opening hours and visit packs.
