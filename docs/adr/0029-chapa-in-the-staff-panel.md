# ADR 0029: Chapa in the staff panel

- **Status:** Accepted
- **Date:** 2026-11-16

## Context

A gym's members pay online through the gym's own Chapa account (ADR 0009). Staff send them payment links, and members pay in the app (ADR 0013). The panel (ADR 0017) left all of this to the API:
- the owner could connect Chapa only with `PUT .../chapa-account`;
- the desk couldn't make, see, check or cancel a payment link;
- the Inbox's "Refund needed" message (ADR 0027) opened the member, because the link had no page.

ADRs 0017, 0018 and 0023 deferred payment links until Chapa could be connected in the panel. Going live (ADR 0028) made the gap plain: a gym run from the browser couldn't take its first online payment.

## Decision

### Online payments page

- **Who sees it.** A page, **Online payments**, shows owners, managers and accountants (`ChapaAccountPolicy::view`) whether Chapa is connected. It shows:
  - a test or live key;
  - the key's last 4 characters;
  - who connected it, and when.

  The key itself is never shown.
- **Owners only** connect, replace or disconnect the key, as in the API.
- **One rule for the key.** It moves into `App\Rules\ChapaSecretKey`, which the API's request and the panel share. The rule:
  - refuses the public key;
  - asks Chapa whether it accepts the key;
  - when Chapa doesn't answer, throws `ChapaRequestFailed`. The API still answers 502; the panel shows the message on the field and saves nothing.

### Payment links

- **A list.** **Payment links** lists the gym's links, newest first. It is limited to the staff member's branches through `PaymentLink::forAnyOf()` (ADR 0025), and a link at another branch is a 404. There are filters for status, branch and member, so links needing a refund stand out.
- **Who sees them.** `PaymentLinkPolicy` decides, as in the API: owners, managers, accountants and receptionists. Trainers don't see links.
- **A link's page** shows:
  - what the link is for, and its status;
  - the payment it recorded;
  - while it waits, the checkout address, with a copy button, to paste into Telegram, WhatsApp or an SMS.
- **Actions on a link.** **Check now** asks Chapa whether the link was paid and records the payment, as the API's check does. **Cancel link** withdraws a waiting link.
- **Creating a link.** **Create payment link** sits on a membership's page, beside Take payment. It takes:
  - the amount, defaulting to what is still due;
  - one of the staff member's branches.

  It then opens the new link, ready to copy. A member with the app also sees it there, so there is no new SMS.
- **One set of rules for a new link.** They move into the domain as `PaymentLink::refusalToOpen()`, with new `PaymentRefused` refusals. `StorePaymentLinkRequest` calls it, with the same messages. A link is refused when:
  - Chapa isn't connected;
  - the membership already has an open link;
  - the membership doesn't owe the amount.
- **The Inbox.** "Refund needed" now opens the link instead of the member.

### Deferred

- **Marking a refund done.** The gym refunds from Chapa's dashboard, and the link stays "refund needed". ADR 0031 added it.
- **Texting the link to the member** from the panel. ADR 0031 added it.
- **Showing a membership's open link on its page.** For now it is found through the list's member filter. ADR 0031 added it.

## Consequences

- A gym can be run from the browser end to end:
  - the owner connects Chapa;
  - the desk sends links;
  - staff check them and see what needs refunding;
  - all without the API.
- The API and the panel can't drift apart on the key or on when a link may be made: both ask the same rule and the same domain method.
