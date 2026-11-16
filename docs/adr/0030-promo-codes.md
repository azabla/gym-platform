# ADR 0030: Promo codes

- **Status:** Accepted
- **Date:** 2026-11-16

## Context

Owners and managers can sell below a plan's price at the desk, typing an amount or a percentage with a reason (ADR 0007). A member buying in the app (ADR 0013) always paid the full price. Gyms advertise offers on Telegram and posters ("NEWYEAR: 20% off") and had no way to honour them in the app, or to let the front desk honour them without an owner or manager.

## Decision

### A code and its limits

- **A gym-owned code.** A promo code (`promo_codes`, Memberships domain) is a code and a discount in the desk's format (`20%` or `500`), worked out by the existing `Discount` value object. It can have:
  - first and last days, the gym's dates, both included;
  - a total number of uses;
  - once per member, on by default;
  - some plans only, in `plan_promo_code`, with composite foreign keys like `branch_plan`.
- **What members type.** Codes are 3 to 30 letters, digits or dashes. They are kept in capitals and found however they are typed. Each is unique in the gym, archived codes included, so a code on an old poster never means something else.
- **A use is a membership sold with the code.** `memberships.promo_code_id` names it, and `discount_reason` reads "Promo code NEWYEAR", so the existing check constraint, resources and reports keep working. Cancelled memberships still count.
- **One place decides.** `PromoCode::refusalToUse(Member, Plan, today)` decides for the app, the API and the panel, with `PromoCodeRefused` mapped to a 422 on `promo_code`. A code is refused when:
  - it hasn't started, or has ended;
  - it is used up;
  - the member has already used it;
  - it isn't for the plan;
  - it takes more off than the plan costs.
- **Archiving** (a soft delete) stops new uses, and the code can be restored. A used code keeps its text, so it can still be found.

### In the members app

- **Previewing prices.** `GET .../plans?promo_code=` adds `price_with_code` to each plan, or null where the code doesn't apply. A code the member can't use at all is a 422.
- **Buying.** `POST .../plans/{plan}/purchase` takes `promo_code`.
  - The link is for the price with the code, and keeps the plan's price in `list_amount_minor` with the code.
  - When Chapa confirms, the membership records that list price, the price paid and the code.
  - The same plan and code give the same link; another code or none cancels it.
- **Validity is checked when buying starts.** A code that ends or runs out before Chapa confirms is still honoured, because the member has paid that price. So a code can go a little over its total when several members are paying at once.
- **A code that makes a plan free** can't go to Chapa. The app says to show it at the desk.

### At the desk

- **API.** Selling takes `promo_code` in place of a typed discount, never both.
- **Who may use a code.** Any staff member who sells, because an owner or manager approved the code. Typed discounts stay for owners and managers.
- **Panel.** Sell membership has a **Promo code** field that shows the price with it.

### Managing codes

- **Who.** Owners and managers (`PromoCodePolicy`). A manager limited to some branches manages only codes whose plans are all sold only at their branches; a code for every plan needs staff at all branches, as with plans (ADR 0023).
- **API.** `GET`, `POST`, `PATCH` and `GET {id}` on `organizations/{gym}/promo-codes`, plus `.../archive` and `.../restore`. Each answer includes `uses`.
- **Panel.** A **Promo codes** page lists codes with their status (upcoming, active, ended, used up, archived) and uses ("3 of 50").
- **Demo data.** The demo gym has `NEWYEAR`: 20% off any plan, once per member.

### Deferred

- Codes for one member only, or per-member discounts (student, staff). ADR 0032 added discount groups.
- A report of the codes used and what they cost. ADR 0032 added the discounts report.
- Reserving a use while a member is paying, to keep exactly to a total.

## Consequences

- A gym can run an offer end to end: make the code, share it, and see it used in the app and at the desk, with every membership saying which code it used.
- The front desk honours offers without an owner or manager, and owners and managers control what is on offer.
- The members app gains the code field on the plans screen and at purchase (`docs/api/members-app.md`).
