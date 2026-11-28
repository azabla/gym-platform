<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

## Commits

- Commit with the developer's own git identity (`git config user.name` and `user.email`), never as an AI assistant.
- Never add AI co-author lines (such as `Co-Authored-By: Claude …`) or AI session links to commit messages or pull request descriptions.

## How we work

This is how the project has been built so far; keep to it so any session, local or in the cloud, carries on the same way.

### The loop for each piece

1. Ask the developer the open questions first (with choices and a recommended one), then write a plan and wait for approval before changing code.
2. Build it in small steps, one commit per step, each with its tests. Push after each step and keep CI green.
3. Finish each piece with an ADR in `docs/adr/` (next number after the last one), and update `docs/README.md`, `docs/development-setup.md` and, for the members app, `docs/api/members-app.md`.

### Branches and pull requests

- Branch names read as a person would write them: `feature/visit-packs-and-hours`, `docs/how-we-work`.
- One pull request at a time into `main`, never stacked on another branch. Open the next only after the previous one merges.
- Before opening a pull request, merge `main` into the branch (a merge commit, no rebase or force-push), so it shows only its own work.
- Pull request descriptions say what the change does and why, with no AI attribution. If a tool appends a "Generated by Claude Code" footer, remove it.
- Don't open a pull request unless the developer asks for one.

### Checks before every commit

- `php -l` and `vendor/bin/pint --dirty --format agent` on the changed PHP files.
- CI (GitHub Actions) runs Pint, Larastan level 6 and the Pest suite on PHP 8.4 with PostgreSQL 17; it must pass on every push.
- New migrations only; never edit one that has been merged. No new packages without asking.

### Architecture rules that bite

- Gym-owned models use `BelongsToOrganization`: their queries need a current organization. Work outside a request (commands, jobs, listeners) runs inside `CurrentOrganization::run($organization, fn () => ...)`; platform-wide queries use `acrossOrganizations()`.
- Links between gym-owned tables are composite foreign keys on `(organization_id, id)`, so no row can point at another gym.
- Which domains may depend on which is enforced by `tests/Unit/ArchitectureTest.php` (for example, Memberships doesn't depend on Attendance or Billing). When a domain needs another's data, look it up in the HTTP layer or in a listener.
- Domains announce events (dispatched after commit); messages to members live in `App\Notifications`, and listeners in `App\Listeners`, never inside `app/Domains`.
- Store times in UTC (convert before saving an offset time). "Today" is the gym's date, `Organization::today()`, never the server's.
- Message text lives in `lang/en` and `lang/am` under the same keys and placeholders, never in code; `AmharicMessagesTest` checks they match (ADR 0034).
- Staff panel text is English inside `__()`/`trans_choice()` with placeholders, its Amharic in `lang/am.json`; `AmharicPanelTextTest` fails CI on a missing one and `AmharicPagesTest` on English left on a page. All Amharic, panel and messages, uses the words in `docs/amharic-glossary.md`. Dates go through `Dates` (panel) or `ReadableDate`: Ethiopian with the Gregorian beside it in Amharic (ADR 0041).
- Money is a `Money` value object, stored as minor units; phone numbers are `PhoneNumber`, stored as E.164.
- Money-taken figures (takings, revenue, reports) use `Payment::moneyTaken()` or `PaymentMethod::moneyTaken()`: brought-in payments, recorded only by a member import, count towards balances but never as money taken; the desk offers `PaymentMethod::forTheDesk()` (ADR 0044).
- There are two panels: staff at `/staff` (the default, tenant = gym) and admin at `/admin` (platform admins, English, no tenant; every gym-owned query there uses `acrossOrganizations()`). Inside a gym Filament also scopes resource models to it, so cross-gym lookups there use `withoutGlobalScopes()` (ADR 0042).
- A paused gym (`Organization::isSuspended()`) refuses staff, the APIs (`403 GYM_PAUSED`) and the door, and sends nothing: new scheduled work loops over `Organization::query()->active()`, and new member or staff messages extend `MemberNotification`/`StaffNotification` or use `HeldWhileTheGymIsPaused` (ADR 0043).
- Every action that moves a gym's money calls `Activity::record()` inside its own transaction (the one direct call between domains, so the log is never lost); records staff edit in forms use the `LogsMoneyChanges` trait. A new money action must log itself, and new log actions need a line in `ActivityText` and Amharic (ADR 0046).
- Phones sign in through `SignIns::start()` with their app (staff or members) and are signed out through `SignIns::end()`, which revokes their QR credentials; never `createToken()` or `$token->delete()` directly (ADR 0046).
- The platform bills gyms in `PlatformBilling`, which depends on Organizations only (ADR 0045). `platform-billing:run` is the one scheduled job that includes paused gyms; bill messages to owners extend `GymBillNotification`, which a pause never holds back. A pause for an unpaid bill (`SuspensionCause::UnpaidBill`) reopens on payment; an admin's never does. SQL for "today" uses `GymBills::gymTodaySql()` (the app's clock), never the database's `now()`.

### The staff panel standard (ADR 0033)

- Lists start from `App\Filament\Staff\Support\Tables::standard()`: badges from `Badges`, money via `Tables::money()`, date ranges via `Tables::dateRange()`, toggleable extra columns, an empty state, and a filter for every status, branch (only theirs), date and owner. Keep existing filter keys; links use them.
- Forms come in sections with a description and icon, placeholders, helper text, prefixes or suffixes, and live previews where they help. Action modals get an icon and a summary line.
- Look things up once per page for list columns (`Tables::pageRecords()`), never once per row.

### Larastan lessons

- No nullsafe call on the left of `??`; write it out with a null check.
- Give closures passed to `CurrentOrganization::run()` a return type, and type closure parameters with plain types, not generic `Builder`/`Collection`.
- Cast `pluck()` results before using them as typed values.
- Don't wrap something already a list in `array_values()`.

### Where to look

- `docs/README.md`: where things live, conventions and the glossary.
- `docs/adr/`: why things are the way they are (0001 to 0046 so far).
- `docs/api/members-app.md`: the contract with the members app.
- `/docs/api`: the API docs Scramble generates from the code, for staff signed in to the panel (ADR 0021).
- Not built yet, and candidates for the next piece (agreed 2026-11-28): admin 2FA, support access and closing a gym; expenses and profit; a member's data on request and clearing old notifications; desk, door and report extras; the Ethiopian date picker and holidays. Trainers come later as one whole piece. The real server (always on, separate worker and scheduler, Redis, a domain; ADR 0028) waits until a server is bought; until then everything runs on staging.
