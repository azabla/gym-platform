# ADR 0017: The staff panel

- **Status:** Accepted
- **Date:** 2026-10-26

## Context

Staff could only reach the gym through the API, from the Flutter app. ADR 0004 planned a web dashboard for the front desk, and owners want to see the day and look members up from a computer at the desk or at home.

Building that by hand means a second frontend to keep in step with the API. The figures it shows must agree with the API's, and the same roles, branches and gym boundaries must hold.

## Decision

### Filament, at /staff

- **Filament 5** (with Livewire) draws the panel from PHP: tables, filters and pages, with no separate frontend to build or deploy. It is the only new package.
- **Each gym is a tenant,** at `/staff/{slug}`, using the same slug as the API's URLs. Someone who works at several gyms switches between them from the menu.
- **Staff sign in with their email and password** on the panel's own session guard, `staff_panel`. As in the API, the email matches however it is typed. Members sign in by phone and have no password, so they can't get in.
- **Filament's assets** are published by `composer install` (`filament:upgrade`) and are not committed.

### Who is signed in, without Identity knowing about gyms

Filament asks the signed-in user which gyms they may enter. Identity may not depend on Organizations, so `User` can't answer that itself.

- `App\Filament\Staff\PanelUser` extends `User`, on the same `users` table, and answers from the user's staff records.
- The `staff_panel` guard loads users as `PanelUser`. The API and the members app keep using `User`.
- A user who works at no gym is refused (403). A gym the user doesn't work at is a 404, as in the API.

### The current gym and staff member

Gym-owned queries need a current organization (ADR 0003), and every policy reads the current staff record (ADR 0004).

- `ActAsTenant` sets both from the gym in the URL, as `ActAsOrganization` does for the API.
- It is registered as persistent tenant middleware, so it also runs on Livewire's requests.
- So the panel uses the existing policies unchanged: a trainer doesn't see payments, and every record is the current gym's.

### The dashboard

- **One source for both dashboards.** `App\Dashboards\StaffDashboard` holds the sections of ADR 0014, moved out of the API controller unchanged, and the panel's widgets read from it. So the panel and the API always show the same figures.
- **Money and branches.** `StaffRole::seesMoney()` decides who sees the money, and `Staff::limitedToBranchIds()` which branches the figures cover.
- **Filters.** The page filters by period (today by default, at most a year) and by one of the staff member's branches.
- **Widgets:**
  - the money taken, owed and waiting in payment links, for owners, managers and accountants only;
  - check-ins, members and memberships sold, for everyone;
  - the members to call.
- **Architecture tests** keep `App\Dashboards` independent of both the HTTP layer and the panel, and keep the domains from depending on the panel.

### The lists

These are read-only, for looking things up:

| List | What |
|---|---|
| Members | Search by part of a name or the digits of a phone number, as the API does. Archived members are left out. A member's page shows what they owe and links to their memberships, payments and check-ins. |
| Memberships | Each membership's status on the gym's today, filtered by status or member. A membership's page shows what is paid and owed. |
| Payments | Filtered by day, branch, method or member, with what the filtered payments add up to (voided ones don't count). Not for trainers. |
| Check-ins | Times where the gym is, filtered by the gym's day, branch, result or member, with the reason for each denial. |

- **Links instead of relations.** Members doesn't depend on Memberships, Billing or Attendance, so a member's memberships, payments and check-ins are lists filtered to the member, not relations on `Member`.

### Deferred

- Doing things from the panel: registering and editing members, selling, freezing and cancelling memberships, taking, refunding and voiding payments, sending payment links, checking members in, and announcements, plans and branches. All of these stay in the app for now.
- Lists limited to a staff member's branches. Like the API's lists, they show the whole gym.
- Charts over time, comparing periods, and exports (ADR 0014).
- A platform-admin panel across all gyms.
- Password reset, which still needs email or SMS delivery (ADR 0004).

## Consequences

- Owners and the front desk can see the day and look members up from a browser, with the same figures and rules as the app.
- Adding a list is a Filament resource that leans on the existing policies, and the tenancy comes free.
- The project now depends on Filament and Livewire, which must be upgraded together with Laravel.
- A gym's own data is protected twice: by the organization scope (ADR 0003) and by Filament's tenant scoping.
- Panel tests sign in with `actingAsStaffInPanel()`, which also sets the current gym and staff, because `Livewire::test()` skips the panel's middleware.
