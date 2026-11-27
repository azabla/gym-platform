# ADR 0041: The staff panel in Amharic

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Messages to members and staff come in English or Amharic (ADR 0034), but the staff panel itself stayed in English. Many front desk staff read Amharic more easily than English, and they spend their day in the panel.

## Decision

### Which language

The panel follows the staff member's **message language** (`users.message_language`, chosen on **My notifications**), so one setting covers their inbox, pushes, texts and the panel. There's no separate switch.

- `App\Filament\Staff\Middleware\UseStaffLanguage` sets the app's and Carbon's locale on every panel request.
- It is a persistent tenant middleware, so Livewire's requests (tables, actions, modals) get it too.
- The API never changes its locale, so its answers and refusals stay English for the apps.

### What is translated

Everything staff read:
- **Filament's own words** (buttons, pagination, the user menu) come from Filament's Amharic files.
- **Our words** are written in English in the code and passed through `__()` or `trans_choice()`. Their Amharic is in `lang/am.json`, keyed by the English text.
  - Values go in placeholders (`__(':name is let in.', ['name' => …])`), never joined onto a sentence.
  - Counts use `trans_choice()` with explicit ranges (`{1} …|[2,*] …`).
- **Labels Filament makes from names** pass through the same file:
  - resources' model and menu labels, through `TranslatesItsLabels`;
  - custom pages' titles and subheadings, through `TranslatesItsTitle`;
  - charts' headings, through `TranslatesItsHeading`;
  - statuses, methods and roles, through `Badges::label()`;
  - menu groups, through `NavigationGroup`.
- **Validation messages** are in `lang/am/validation.php` for the rules the panel uses. Any rule without one falls back to English.
- **The domains' refusals** (`MembershipRefused`, `PaymentRefused` and the rest) are written with `__()` and placeholders, so the panel shows them in Amharic and the API in English.
  - `InvalidDiscount` is the exception: the `Discount` value object runs without Laravel. It keeps an English message, plus its `text` and `values`, and the panel shows `__($exception->text, $exception->values)`.

### Dates

Dates stay Gregorian, with Amharic month and weekday names. Formats with month or day names use `translatedFormat()`, and Filament's `->date()` already does. Dates already written on the Ethiopian calendar, such as yearly closures (ADR 0039), are unchanged.

### Keeping it complete

`tests/Feature/Lang/AmharicPanelTextTest.php` makes a missing translation fail CI:
- **Text in the code.** Every `__('…')` and `trans_choice('…')` text in `app/Filament`, `app/Insights` and the domains' exceptions must be in `lang/am.json`.
- **Runtime labels.** So must labels worked out as the panel runs: resource, page and chart labels, every badge, check-in reason and insight.
- **Placeholders.** Each Amharic entry has the same placeholders as its English.
- **Lang file names.** No text may be named like a lang file (`SMS` and `lang/en/sms.php`), because `__()` would return that whole file.

## Consequences

- **New panel text.** Every new text needs its Amharic in `lang/am.json` in the same change, or CI fails. As with messages (ADR 0034), a fluent speaker reviews new Amharic.
- **Changing English.** The English text is the key, so changing it means changing its key in `lang/am.json` too. The test points out the missing one.
- **What stays English:**
  - the API, including its refusals and validation messages;
  - CSV reports and insight files, whose columns stay English for spreadsheets and other tools;
  - the gym's own data (plan names, branch names, announcements);
  - the English sample beside the English choice on My notifications.
- **ADR 0034's "the panel's own words stay English"** no longer holds; this ADR replaces that line.

### Deferred

- Dates on the Ethiopian calendar throughout the panel.
- A panel language separate from the message language, should staff ask for one.
- Amharic answers from the staff API for a future staff app, using `Accept-Language` as the members app does.
- Amharic column names in CSV files.
