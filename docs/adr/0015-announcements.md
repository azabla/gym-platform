# ADR 0015: Announcements

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

The platform tells members what happened to them: payments, freezes, turned-away visits and expiring memberships (ADR 0012). Gyms also need to tell their members things in their own words: holiday hours, a closure, new classes. Today they do it by word of mouth at the desk, or from a personal phone.

The Volt doc lists announcements as one of the app's message types. ADR 0012 left them for later.

## Decision

### Who speaks for the gym

- **Owners and managers** post, change and cancel announcements.
- **Every staff role** can read the list, so the desk knows what members were told.
- **Staff limited to some branches** must choose one of their branches. They can't post to the whole gym, or change an announcement to it.

### What an announcement is

- A `title` (up to 80 characters) and a `body` (up to 1,000), in the gym's own words. Unlike the platform's messages they aren't translated, so the app shows them as written in every language.
- **Audience:**
  - every branch, or one, by the member's home branch;
  - members with a membership valid today;
  - optionally also members whose membership ended in the last 30 days (`include_recently_expired`), to win them back.
- Archived members and members of a cancelled membership never receive one.
- **Inbox and push only, never SMS.** An SMS to every member costs the platform per message, and members without the app see the gym's posters anyway.

### When it goes

- **Now**, or **scheduled** at `send_at`. Times are kept in UTC, whatever offset they come with.
- A scheduled announcement can be edited or cancelled until it goes. Once sent it can't be changed, because members have already read it.
- The audience is worked out **when it goes**, not when it's posted, so a member who renews in the meantime hears it. The answer to posting shows how many members it would reach now.
- `announcements:send-due` runs every minute and queues `SendAnnouncement` for each due announcement, so a scheduled one goes out within a minute of its time.

### Sent once

- `SendAnnouncement` first **claims** the announcement: one conditional update sets `sent_at` only if it is due, unsent and not cancelled. Only the job that gets the row sends it, so it goes out once even if it's queued twice, and never if it was cancelled while it waited.
- Edits and cancellations use the same condition, so a request racing the job either changes the announcement before it goes or gets a `422`.
- It then tells its audience in chunks of 200 and records `recipients_count`.

### In the app

- The inbox entry has type `announcement`, with `data.announcement_id`, under a fourth push topic, `announcements`, on by default (ADR 0012).
- The push shows the title and the first 150 characters of the body; the inbox keeps all of it.
- `GET me/gyms/{gym}/announcements/{id}` shows it in full, only to members who received it.

### Domain boundaries

- `App\Domains\Announcements` holds the model, its audience and the claim. It reads Members and memberships; no other domain depends on it (an architecture test).
- The message (`App\Notifications\AnnouncementMade`), the job (`App\Jobs\SendAnnouncement`) and the command sit outside the domains, as ADR 0012 requires.

### API

Staff, under `/organizations/{gym}/`:

- `GET announcements`
- `POST announcements`
- `PATCH announcements/{id}`
- `POST announcements/{id}/cancel`

The members app: `GET me/gyms/{gym}/announcements/{id}`.

### Deferred

- Announcements by SMS.
- Images and links in an announcement.
- Audiences by plan, or by members who owe money.
- Read counts for staff.
- Translating an announcement into the member's language.

## Consequences

- Gyms reach every member with the app at once, at no cost per message, and can schedule holiday notices ahead.
- A sent announcement stays in members' inboxes as written; a mistake needs a second announcement to correct it.
- If a queue worker stops halfway through a large audience, the announcement is already marked sent, so some members miss it rather than others receiving it twice.
- Production needs the scheduler running every minute (ADR 0002).
