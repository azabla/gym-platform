# ADR 0012: Reminders and notifications

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Members can sign in, pay and check in (ADRs 0010 and 0011), but the platform never tells them anything. Many find out their membership ended when they're turned away at the door.

The members app's team describes what the app expects in the Volt doc: an inbox with an unread count, push notifications through Firebase Cloud Messaging (FCM), push-token registration and per-gym preferences. Many members won't install the app, so expiry also needs to reach a plain phone.

## Decision

### What members are told

| Type | When | By SMS too |
|---|---|---|
| `membership_expiring` | 5, 3 and 1 days before the membership ends, unless a later one is sold | at 3 days |
| `membership_expired` | the day after it ends, unless a later one is sold | yes |
| `membership_frozen` | staff freeze the membership | no |
| `payment_succeeded` | a payment is recorded, at the desk or through Chapa | no |
| `visit_denied` | a check-in of a known member is turned away | no |

- The type names are Volt's. Invoices, failed payments, renewals and announcements wait.
- **Reminders** go out from 09:00 in the gym's time zone. `notifications:send-membership-reminders` runs every hour, and `sent_reminders` (unique on membership, type and days) makes each reminder go out once, however often it runs.
- **Lock-screen safe:** messages name the gym, the plan and the branch, never a person's name, phone number or payment reference.

### Who receives them

- **The member record**, made notifiable. So:
  - each gym has its own inbox;
  - SMS goes to the member's phone, app or not;
  - pushes go to every phone signed in as the user with that phone number (ADR 0010).
- **The inbox** is Laravel's database notifications. It keeps which message it is and its details, not the text, so the app gets the title and body in the language it asks for (`Accept-Language`).
- **Preferences:** pushes about `membership`, `payments` and `visits` can be turned off for each gym. All start on. They decide pushes only: the inbox gets everything, and the expiry SMS goes out regardless, because members without the app can't set preferences.

### Push through Firebase, without a package

- `PushSender` mirrors `SmsSender` (ADR 0010). `PUSH_DRIVER` picks the driver:
  - `log` writes each push to the log, the default for development and tests;
  - `fcm` calls FCM's HTTP v1 API.
- The FCM driver signs a token request with the platform's service account (a JWT signed with `openssl_sign`), and reuses Google's access token for 55 minutes. The key file's path is `FCM_CREDENTIALS`, kept out of git (`storage/firebase/`).
- **Push tokens:** one per sign-in token, so one per phone, with the phone's language.
  - A token moving to another sign-in leaves the old one.
  - Signing out deletes it, through a cascade on the sign-in token.
  - A token FCM answers `UNREGISTERED` or `INVALID_ARGUMENT` for is deleted.
  - One phone failing doesn't stop the others.

### Languages

- Messages are in `lang/en/notifications.php`. Amharic is added as `lang/am/notifications.php` with the same keys; a key missing there falls back to English.
- Pushes use the phone's language, from its push token. SMS is in English for now, because the platform doesn't know a member's language without the app.

### Domain boundaries

- Billing, Memberships and Attendance announce what happened with events dispatched after commit: `PaymentRecorded`, `MembershipFrozen` and `CheckInDenied`. Nothing is sent for work that was rolled back.
- `App\Listeners\TellTheMember` turns them into messages, from `App\Notifications`. Each runs at the event's gym, because a payment confirmed by Chapa has no gym request around it.
- The `Notifications` domain keeps the plumbing only (SMS, push, topics), and still depends on no other domain.
- Messages are queued. Their details are worked out when they're created, at the gym, so the queue worker sending them queries nothing scoped to a gym.

### API

The members app:

- `PUT` and `DELETE me/push-token`;
- `GET me/gyms/{gym}/notifications`, with `meta.unread_count`;
- `POST me/gyms/{gym}/notifications/{id}/read` and `POST me/gyms/{gym}/notifications/read-all`;
- `GET` and `PATCH me/gyms/{gym}/notification-preferences`.

The Volt doc's paths differ (`/devices/current/push-token`, no gym in the path). The app follows the backend, as agreed in ADR 0011; `docs/api/members-app.md` is the contract.

### Deferred

- Announcements from the gym.
- Amharic messages, until a fluent speaker writes them.
- Invoices, failed-payment messages and renewal messages.
- Staff notifications.
- Delivery reports from FCM and AfroMessage.

## Consequences

- Members hear before their membership ends, by SMS even without the app, so fewer are surprised at the door.
- The platform pays for two SMS for each membership that runs out without a renewal; the others are free.
- Production needs the queue worker and the scheduler running (ADR 0002), and the Firebase projects, one per environment, with their service account keys.
- Until `PUSH_DRIVER=fcm` is set, pushes only appear in the log; the inbox works either way.
- A new message type is a notification class, a listener or a line in the reminder command, and its keys in the language files.
