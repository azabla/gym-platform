# ADR 0018: Working the front desk in the staff panel

- **Status:** Accepted
- **Date:** 2026-10-26

## Context

The staff panel (ADR 0017) only looked things up. The front desk still needed the app to register members, sell, take money and check members in.

The domain methods (`Membership::sell()`, `freeze()`, `cancel()`, `Payment::receive()`, `CheckIn::atDesk()`) do the work, but many of the rules around them lived only in the API's form requests:
- the clash check when selling;
- every rule about a freeze's dates and the freeze days left;
- what can be cancelled, and which freezes can end early;
- duplicate transaction numbers.

Only the money refusals on Payment (ADR 0008) and the door's rules (ADR 0011) were in the domain. A panel calling the domain directly would have skipped the rest.

## Decision

### The rules move into the domain

- **Membership refusal methods.** `Membership::refusalToSell()`, `refusalToFreeze()`, `refusalToEndFreeze()` and `refusalToCancel()` say why something isn't allowed, or return null. This is the pattern Payment's `refusalTo…` methods already follow.
- **`MembershipRefused`** carries the message and the field it belongs to, as `PaymentRefused` does. The API answers it with a 422.
- **Transaction numbers.** `Payment::recordedWithReference()` finds a payment that already has the number.
- **The API's requests call them,** with the same messages, so the API's tests pass unchanged.
- **The API and the panel can't drift apart.** A rule written once applies to both.
- **What stays outside the domain:**
  - permissions, in the policies, which both use;
  - checks on what was typed (required, formats, phone numbers and amounts, with the same rule classes `ValidPhoneNumber` and `ValidMoneyAmount`), on each side.

### What the panel does

| Where | Action | Who (the policies decide) |
|---|---|---|
| Members list | Register member; check in | Owner, manager, receptionist register; everyone checks in |
| Member's page | Edit, archive, sell membership, check in | Owner, manager, receptionist; check in for everyone |
| Membership's page | Take payment, freeze, end freeze | Owner, manager, receptionist (an accountant also takes payments) |
| Membership's page | Cancel | Owner, manager |
| Membership's page | Refund, on a cancelled membership | Owner, manager, accountant |
| Payment's page | Void | Owner, manager, accountant |

- **Defaults** follow the API:
  - a sale starts on the member's first free day;
  - a payment suggests what is due, and a refund suggests what was paid;
  - the branch is preset when the staff member works at only one;
  - dates are the gym's today.
- **The same people as in the API** backdate a sale, freeze or payment, give a discount, or freeze beyond the allowance.
- **A refusal stops the action** and shows the domain's message, the same words the API answers with.
- **Actions are classes** in `App\Filament\Staff\Actions`, so the members list and a member's page share Check in. `MoneyAction` holds what taking a payment and refunding have in common.

### Deferred

- Payment links from the panel, which need Chapa (ADR 0009).
- Plans, branches, opening hours, staff and announcements from the panel.
- Showing a refusal next to its field rather than as a notification.

## Consequences

- The front desk can work entirely in a browser, with the same rules and permissions as the app.
- A new rule about memberships goes on Membership, and both the API and the panel follow it.
- The panel's actions are covered by Livewire tests under `tests/Feature/Filament/Staff`, beside the API's tests, which still prove the rules.
