# ADR 0033: The staff panel's look and conventions

- **Status:** Accepted
- **Date:** 2026-11-16

## Context

The staff panel (ADR 0017) grew a page at a time and worked, but looked plain:
- an amber, unbranded, flat menu;
- money as plain text, status badges mostly without colour;
- few filters (none on plans, branches or announcements);
- forms in one section with little help.

With demo data loaded (`make demo`), the developer asked that **every table look good with all the filters staff need, and every form look good and be easy to use**, from now on. That calls for one pass over the whole panel, and a standard later pieces keep to.

## Decision

### Brand and layout

- **Brand.** The current gym's name as the panel's brand. Orange on slate (Emerald, Amber, Rose and Sky for success, warning, danger and info), the Inter font.
- **Theme and sidebar.** Light or dark as the device is, with a switch in the user menu, and a sidebar that folds away.
- **Menu groups** (`App\Filament\Staff\NavigationGroup`). The Dashboard and Inbox sit above them.

  | Group | Pages |
  |---|---|
  | Front desk | Members, Memberships, Check-ins, Announcements |
  | Money | Payments, Payment links, Online payments |
  | Gym setup | Plans, Promo codes, Discount groups, Branches, Staff |
  | Reports | Reports |

  Each item has its own icon.
- **Reports page.** It gives every CSV report as a card (payments, memberships sold, discounts, check-ins, members), each shown only to the roles allowed it. The lists keep their own download buttons.

### One look for statuses

`App\Filament\Staff\Support\Badges` gives every status, method and role one label, colour and icon:
- membership status (active green, frozen sky, upcoming amber, expired gray, cancelled rose);
- payment link status, check-in result, payment type and method, staff role, announcement status, Chapa key mode, and promo code status.

Badge columns, record pages and filter options all use it. It lives in the panel because the domains never depend on Filament (`tests/Unit/ArchitectureTest.php`).

### The table standard (`App\Filament\Staff\Support\Tables`)

- **Layout.** `Tables::standard()` sets:
  - striped rows, 25 to a page (10, 25, 50, 100);
  - filters above the list, collapsible, with indicators and reset;
  - an empty state with an icon, a heading and what to do.
- **Columns:**
  - statuses and methods as badges (`Tables::badge()`);
  - money aligned right in tabular figures (`Tables::money()`), with colour where it means something (owed in rose, refunds in red, voided struck through);
  - dates in the gym's time zone as "24 Oct 2026, 01:30";
  - the main columns searchable and sortable, with a search placeholder saying what it searches;
  - extra columns toggleable.
- **Filters.** Every list has the filters staff need. Date ranges use `Tables::dateRange()`, which compares moments in the gym's days. Branch filters offer only the staff member's branches.
- **Filter keys stay.** Existing keys never change, because links use them (`member`, `status`, …).
- **One query per page.** What a list works out per row (a member's membership today, balances, discount groups) is looked up once for the page, not once per row.

### The form standard

- **Layout.** Sections with a heading, a short description and an icon, on a 2- to 4-column grid.
- **Fields:**
  - placeholders that show the format ("0911 23 45 67", "20% or 500");
  - helper text where a rule isn't obvious, and prefixes and suffixes ("ETB", "days", "visits");
  - sensible defaults, and fields that appear only when they apply.
- **Live previews where they help:**
  - Plan: the dates and price a day of a membership bought today.
  - Promo code and discount group: what they make of Monthly.
  - Sell membership: the price with the member's group or code.
  - Staff: what the chosen role can do.
- **Action modals** have an icon, a width, and a line saying where things stand. Take payment and Refund show the membership's price, paid and owed. Each desk action has its own icon.
- **Validation messages** stay the domain's and the API's own (ADR 0018).

### Record pages and the dashboard

- **Record pages.** Member, membership, payment and payment link pages open with "At a glance": status badges and money in colour. Sections with icons follow, with copyable phone numbers and references.
- **Dashboard.** Figures have icons, colours and descriptions, with the last week of takings and check-ins as sparklines. Owed, Open payment links and Refunds to make open the lists behind them.
- **Inbox.** It can show unread messages only.

### Deferred

- A custom Filament theme (Tailwind build) for finer styling.
- Showing an announcement's reach before it is sent.
- Saved table views per staff member.

## Consequences

- **Every new page follows this standard.** Badges come from `Badges`, lists start from `Tables::standard()`, every status, branch, date and owner gets a filter, and forms come in sections with help. Reviews check for it.
- **The panel now carries more in the browser** (more columns and filters), but each list still runs one query per kind per page.
- **Tests updated.** Tests that checked Filament's old date format now check the gym's format.
