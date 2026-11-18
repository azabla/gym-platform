# ADR 0035: Push and SMS to staff

- **Status:** Accepted
- **Date:** 2026-11-19

## Context

Staff messages (ADR 0027) went only to the Inbox, in the panel and the API:
- a member paid online;
- Chapa took money that must be refunded;
- a member froze their membership in the app, or ended a freeze early;
- the morning list of members to call.

A staff member away from a screen learnt of them only when they next opened the Inbox. Pushes already reached members' phones (ADR 0012), but push tokens belonged to a person, not an app. A staff member who also uses the members app would have had staff messages arrive there.

## Decision

### Topics and choices

Staff choose by four topics (`App\Domains\Notifications\Enums\StaffNotificationTopic`):

| Topic | Messages | Roles that hear it |
|---|---|---|
| `online_payments` | Paid online | Owner, manager, accountant |
| `refunds` | Refund needed | Owner, manager, accountant |
| `app_freezes` | Frozen in the app, back early | Owner, manager, receptionist |
| `members_to_call` | The morning list | Owner, manager, receptionist |

- **Defaults.** Each topic comes **by push** until the staff member turns it off, and **by SMS** only once they turn it on, so nobody runs up texts they didn't ask for.
- **Where it is kept.** The choice is per gym, on `staff.notification_preferences`, because someone who works at two gyms may care about one more than the other.
- **The Inbox** gets every message whatever the choices.

### Nights

- **No texts at night.** No SMS goes to staff from 21:00 to 07:00 where the gym is. The message still reaches the Inbox and goes by push.
- **Nothing is held for later.** By morning the Inbox has it, and the morning list is sent at 08:00 anyway.

### Which phones

- **The push token's app.** A push token now records which app it is for: `members` (the default, and every token from before) or `staff`. The staff app sends `"app": "staff"` with `PUT /me/push-token`.
- **Routing.** Member pushes go to members-app tokens only, staff pushes to staff-app tokens only.
- **SMS** go to the staff member's user's phone. Older accounts with only an email get none.

### Text

- **Push.** It uses the Inbox title and body.
- **SMS.** It has its own short line in `lang/{en,am}/staff_notifications.php` and starts with the gym's name, since the same person may work at two gyms.
- **Language.** Both are written in the staff member's message language (ADR 0034), not the phone's, like their Inbox.
- **Push data.** It carries `type`, `notification_id`, `gym` (the slug) and what to open (`payment_id`, `payment_link_id`, `membership_id` or `member_id`).

### Choosing

- **Panel.** The user menu's page is now **My notifications**. It shows the message language and, at this gym, a Push and an SMS switch for each topic the staff member's role hears about. Trainers hear nothing and see only the language. SMS switches are disabled without a phone number.
- **Staff API.** `GET` and `PATCH organizations/{gym}/notification-preferences` return and change `{"refunds": {"push": true, "sms": false}, …}`. Each staff member changes only their own.

## Consequences

- **SMS cost.** Staff texts are the gym's cost like members'. With SMS off by default, a gym pays only for what its staff ask for. An Amharic text costs about two parts (ADR 0034).
- **Staff app work.** The staff app must send `"app": "staff"` with its push token, and handle the staff push types. Until then staff pushes reach no phone, and messages stay in the Inbox as before.
- **Members app.** Nothing to change: it gets only members' pushes, as before.

### Deferred

- **Email to staff.**
- **Quiet hours** chosen by each staff member.
- **A daily digest** instead of one push per message.
