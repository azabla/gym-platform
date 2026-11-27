# ADR 0034: Message languages (English and Amharic)

- **Status:** Accepted
- **Date:** 2026-11-18

## Context

Every message was written in English:
- texts to members (expiry, payment links);
- the sign-in code and the staff account texts (written into the code itself);
- the staff Inbox.

Pushes and the app's inbox already took the phone's language (`PushToken.locale`, `Accept-Language`, ADR 0012), but there was no Amharic to give them. Most members of an Ethiopian gym read Amharic first, and an SMS reaches members without the app, so it can't ask the phone.

## Decision

### Languages

`App\Domains\Shared\Language\Language` lists the languages messages are written in: English (`en`) and Amharic (`am`), the names of the folders under `lang/`. It is plain PHP, like the rest of Shared.

### Who reads which language

| Message | Language |
|---|---|
| Member SMS (expiring, expired, payment link) | The member's own message language, else their gym's |
| Member push | The phone's language (unchanged) |
| Member inbox in the app | `Accept-Language` (unchanged) |
| Sign-in code SMS | `Accept-Language` of the request for the code, else English |
| Staff Inbox (panel and API) | The signed-in user's message language |
| Password link and "added as staff" SMS | The user's message language |
| Announcements | The gym's own words (unchanged) |

### Where the choices are kept

- **Gyms.** `organizations.message_language`, English by default. Owners change it on the panel's **Gym settings** page or with `PATCH organizations/{gym}/settings`; every staff member sees it.
- **Members.** `members.message_language`, null meaning "the gym's". The desk sets it on the member form, or with the staff API's `message_language` on a member.
- **The members app.** `PUT /me/language` sets it on every member record of the signed-in phone, at every gym, and on the user. The app calls it when the member changes language, beside the push token's locale.
- **Staff.** `users.message_language`, English by default. Each staff member picks it from **Message language** in the panel's user menu. It covers every gym they work at, because a person reads one language. The panel's own words stay English (since ADR 0041, the panel follows this language too).

A check constraint keeps all three columns to `en` and `am`.

### Text

- **Files.** The Amharic is in `lang/am/notifications.php`, `staff_notifications.php` and `sms.php`, with the same keys and placeholders as English. The sign-in and staff account texts moved out of the code into `lang/{en,am}/sms.php`.
- **Fallback.** A key missing in Amharic falls back to English.
- **Parity test.** `tests/Feature/Lang/AmharicMessagesTest.php` fails when an English key has no Amharic, or the placeholders differ.
- **Review.** The Amharic was drafted without a fluent speaker. It is to be reviewed before members rely on it, and the pull request lists every string.
- **Fixed formats.** Dates (`2026-10-27`) and amounts (`ETB 2,500.00`) keep their format inside Amharic sentences.

## Consequences

- **SMS cost.** An Amharic SMS is Unicode, 70 characters a part instead of 160, so each Amharic text costs about two parts. The texts are kept short.
- **Cheap to add a language.** One more case in `Language` and a folder of files. The parity test then lists everything to translate.
- **Where the language is worked out.** A member's SMS takes their language when it is sent, from the member and their gym, so a queued text follows a change made in the meantime.
- **Staff who are members.** Choosing a language in the members app also changes their staff messages, because it is the same person.

### Deferred

- An Amharic panel interface.
- Ethiopian calendar dates in messages.
- More languages, such as Afaan Oromo and Tigrinya.
