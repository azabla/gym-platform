# ADR 0042: The platform admin panel and opening gyms

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Gyms were added only with `php artisan gyms:create` (ADR 0019), by someone with a shell on the server. The platform had no place to see its gyms, how each is doing, or to change one. Before more gyms join, the people running the platform need that without a shell, and without seeing what belongs to each gym.

## Decision

### Platform admins

- **Who.** `users.is_platform_admin` marks the people who run the platform. Only `php artisan platform:admins add|remove|list` changes it, never a gym or a panel. A new admin with no password gets a link to set one, printed by the command.
- **No mixing.** Being a platform admin gives no way into a gym's staff panel, and working at a gym gives no way into the admin panel.

### A second panel at `/admin`

- **Panel.** `AdminPanelProvider`: its own guard (`admin_panel`) and user (`App\Filament\Admin\AdminUser`, which allows platform admins only), with no tenant. The staff panel stays the default.
- **Sign-in.** By phone and password, through the same `SignsInWithPhone` trait as the staff panel, with "Forgot your password?".
- **Look and language.** Indigo, apart from the gyms' orange, and English: it is for the platform's own people. `AmharicPanelTextTest` checks only the staff panel and what the panels share.
- **Queries.** Being able to open the panel is the authorization (`$shouldSkipAuthorization`). Every query on gym-owned data uses `acrossOrganizations()`.

### Gyms

- **List.** Every gym with its status, owner, branches and figures: members, valid memberships, staff, check-ins in the last 7 days, money taken this month and last activity. It has a search and a status filter.
- **Figures only.** `App\Dashboards\GymFigures` counts and totals in a few grouped queries per page, with "today" and "this month" in each gym's time zone. It never reads a member's name or a payment, so neither reaches the page.
- **A gym's page.** Its figures, details, owners, branches and pause history (ADR 0043).
- **Opening a gym.** `Organization::open(NewGym)` is shared by `gyms:create` and the panel's **New gym**:
  - it creates the gym, its first branch (code MAIN) and its owner;
  - then it invites the owner by SMS.

  `NewGym::rules()` checks both:
  - the address (slug) is small letters, digits and hyphens, not taken, and not one of `Organization::RESERVED_SLUGS`, which now includes `admin`;
  - the time zone is valid;
  - the message language is English or Amharic;
  - the owner's phone is a phone number.

  `gyms:create` gained `--language`.
- **Changing a gym.** Its name, time zone and members' message language. The address stays, as links, the apps and printed QR codes use it.
- **Resend owner invite.** Possible while the first owner has no password. After that they use "Forgot your password?".

## Consequences

- **A first admin needs a shell once:** `php artisan platform:admins add --phone=… --name=…`.
- **Gyms' data stays theirs:** admins see totals, not members or payments. Helping a gym with its own data still goes through the gym's staff.
- **Two panels:** each has its own session guard, and tests set the panel they work in (`Filament::setCurrentPanel`).

### Deferred

- Billing gyms for the platform, and pausing them for not paying automatically.
- Two-factor sign-in for admins.
- Read-only "support access" to a gym's staff panel, logged.
- Closing a gym for good and deleting its data.
