# ADR 0031: Refunds made, open links on memberships, and links by SMS

- **Status:** Accepted
- **Date:** 2026-11-16

## Context

ADR 0029 put Chapa and payment links in the staff panel, and left three gaps:
- **Refunds.** A link Chapa paid when nothing was due any more is marked "refund needed" (ADR 0009), and the Inbox tells the money roles (ADR 0027). Once the gym gave the money back from its Chapa dashboard, nothing said so, so refunds made and refunds owed looked the same.
- **Finding a link.** A membership's open link could only be found through the list's member filter.
- **Reaching members without the app.** The desk had to copy the link into another app.

## Decision

### Marking a refund made

- **A new status.** A link needing a refund can be marked **refunded** (`PaymentLinkStatus::Refunded`). It records:
  - who did it (`refunded_by_staff_id`);
  - when (`refunded_at`);
  - an optional note, such as Chapa's refund reference.

  A check constraint keeps the status and the time together.
- **One place decides.** `PaymentLink::refusalToMarkRefunded()` decides, and `markRefunded()` locks the link, as `settle()` does, so two desks mark it once.
- **Who.** Owners, managers and accountants, the roles that see money, at the link's branch. Another branch's link is a 404 (ADR 0025).
- **API.** `POST payment-links/{id}/refunded` with an optional `note`. A link's answer has `refund` (`refunded_at`, `refunded_by`, `note`) or null.
- **Panel.** **Mark refunded** on the link's page. The members app sees the new status `refunded`.

### Texting a link to the member

- **When.** An open link can be texted to the member's phone, at most once every 10 minutes per link, so a double click or a second desk doesn't send twice. `PaymentLink::refusalToSendBySms()` decides, and `sendToMember()` keeps the time (`sms_sent_at`) and announces `PaymentLinkSentToMember`.
- **How it is sent.** `TellTheMember` sends `PaymentLinkSent` by SMS only, through the queue: "Demo Gym: pay 1,500.00 ETB for Monthly here: …". The text is in `lang/en/notifications.php`. The members app already shows the open link, so there is no inbox message or push.
- **Who.** Anyone who takes payments, at the link's branch.
- **API.** `POST payment-links/{id}/sms` answers `202`.
- **Panel.** **Send by SMS** on the link's page and on its membership's page.
- **Cost.** Each text is an SMS once AfroMessage is live (ADR 0028).

### A membership's open link

- **Staff API.** A membership's answer has `open_payment_link` (`id`, `amount`, `checkout_url`, `created_at`) or null, loaded for all memberships in one query. A link at a branch the staff member doesn't work at is null, as in the lists.
- **Panel.** A membership's page shows the open link to copy, with its amount linking to the link's page.

### Refunds still to make

- **API.** The dashboard's `money` has `refunds_needed` (`count`, `amount`) at the staff member's branches. Like `open_payment_links`, it is current rather than tied to the period.
- **Panel.** The money widget shows **Refunds to make** while there are any, and it opens Payment links filtered to them.

### Deferred

- Refunding through Chapa's API instead of its dashboard.
- Texting links in Amharic.
- Texting other things, such as receipts.

## Consequences

- The money roles see what they still owe members and clear it once paid back, with a record of who did it.
- The desk can reach a member without the app in one click, without paying for repeated texts.
- The members app shows one more link status, `refunded` (`docs/api/members-app.md`).
