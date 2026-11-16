# ADR 0032: Discount groups and the discounts report

- **Status:** Accepted
- **Date:** 2026-11-16

## Context

Promo codes (ADR 0030) let any member buy cheaper with a code. Gyms also price some members lower all the time, such as students or staff families. Until now:
- the desk typed that discount on every sale, and only owners and managers could;
- the members app couldn't give it at all;
- nobody could see what all these discounts cost: typed, coded or otherwise.

## Decision

### Discount groups

- **The group.** A discount group (`discount_groups`, Memberships domain) has a name, unique in the gym among active groups ignoring capitals, and a discount in the desk's format (`10%` or `500`). Archiving it (a soft delete) stops it applying, and it can be restored.
- **Members in a group.** A member is in one group at most, optionally until a day such as a student card's expiry (`member_discounts`, with composite foreign keys). That table lives in Memberships, so the Members domain stays unaware of prices.
- **Who.** Owners and managers create and change groups and put members in them or take them out (`DiscountGroupPolicy`). Groups are gym-wide, like members.

### What a member pays

- **One place decides.** `Pricing::bestFor(member, plan, today, ?promo code)` returns a `PriceChoice`: the plan's price, the member's group's price, or the promo code's, whichever is **lowest**. Rules:
  - discounts are **never** combined;
  - a tie goes to the group, so the code stays unused;
  - a group applies while its until hasn't passed and it isn't archived;
  - a group that takes more off than a plan costs doesn't apply to that plan.
- **What a membership records.** A membership sold at a group's price names it (`memberships.discount_group_id`) with the reason "Discount group Student". A membership names a code or a group, never both.
- **At the desk** (API and panel), a sale without a typed discount charges the best price automatically, for any role that sells. A discount typed by an owner or manager still overrides.
- **In the members app:**
  - each plan has `your_price` and `your_discount` (`source`: `discount_group` or `promo_code`, and its `name`), or null at full price;
  - buying charges that price, and the link keeps the plan's price with the group or code (`payment_links.discount_group_id`; a list amount goes with either), so the membership records them once paid;
  - a plan the group makes free is refused in the app, to be got at the desk.

### The discounts report

- **What it lists.** A **Discounts** CSV of every membership sold below list price in the period, at the staff member's branches (ADR 0025). Columns: sold on, member, phone, home branch, plan, list price, price, amount off, currency, source (typed, promo code, discount group), the code, group or reason, sold by, and whether it was sold in the app.
- **Who.** Owners, managers and accountants.
- **Where.** `reports/discounts` in the API, and **Download discounts** on the panel's memberships list.

### Demo data

`make demo` (beside this piece) adds a Student group (10%) with three members and a Staff family group (50%) with one.

### Deferred

- Groups limited to some plans or branches.
- A member in more than one group.
- Totals of the discounts on the dashboard.

## Consequences

- The desk no longer types a student's discount; the member gets it in the app too.
- Owners see in one file what every discount cost, and why.
- Changing a group's discount changes future purchases only. Memberships keep what they were sold at.
