# ADR 0041: The staff panel in Amharic

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Messages to members and staff come in English or Amharic (ADR 0034), but the staff panel itself stayed in English. Many front desk staff read Amharic more easily than English, and they spend their day in the panel.

A first pass translated the text word for word. Reading it as staff would showed problems:
- plurals made by putting an English "s" on Amharic words (አባልs);
- month names cut short or misspelled;
- words that carried the wrong meaning: a closure was መዝጊያ, a lid, and money taken read as money taken away;
- one idea with several words (freeze had four), and several ideas with one (cancel and void).

So the Amharic is written to one word list, and dates follow the Ethiopian calendar people use.

## Decision

### Which language

The panel follows the staff member's **message language** (`users.message_language`, chosen on **My notifications**), so one setting covers their inbox, pushes, texts and the panel. There's no separate switch.

- `App\Filament\Staff\Middleware\UseStaffLanguage` sets the app's and Carbon's locale on every panel request.
- It is a persistent tenant middleware, so Livewire's requests (tables, actions, modals) get it too.
- The API never changes its locale, so its answers and refusals stay English for the apps.

### One word for each idea

`docs/amharic-glossary.md` is the word list for the panel and for messages. It sets:
- **Style.** Short, informal buttons (አክል, ሰርዝ) and polite explanations and messages (ይምረጡ, አባልነትዎ); common modern spellings (ሰ, not ሠ).
- **Plurals.** Real Amharic plurals (አባላት, ቅርንጫፎች). Days, months and visits stay singular after a number (7 ቀን).
- **Words.** About 90 terms, among them:
  - plan as ጥቅል;
  - check-in as የመግቢያ መዝገብ;
  - closure as የመዘጋት ቀን;
  - freeze as ማቆም;
  - void as ውድቅ አድርግ, apart from cancel (ሰርዝ);
  - the desk as መስተንግዶ.

When a word sounds wrong at the gym, it changes in the glossary first, then everywhere it is used.

### What is translated

Everything staff read:
- **Our words** are written in English in the code and passed through `__()` or `trans_choice()`. Their Amharic is in `lang/am.json`, keyed by the English text.
  - Values go in placeholders (`__(':name is let in.', ['name' => …])`), never joined onto a sentence, because Amharic puts the verb last.
  - Counts use `trans_choice()` with explicit ranges (`{1} …|[2,*] …`).
- **Labels Filament makes from names** pass through the same file:
  - resources' model and menu labels, through `TranslatesItsLabels`. The plural is made from the English singular before it is translated, so Amharic never gets an English "s";
  - custom pages' titles and subheadings, through `TranslatesItsTitle`;
  - charts' headings, through `TranslatesItsHeading`;
  - statuses, methods and roles, through `Badges::label()`;
  - menu groups, through `NavigationGroup`.
- **Filament's own words** come from Filament's Amharic files. Files in `lang/vendor/filament-*/am` replace the lines that were wrong or missing; Laravel merges them line by line, so Filament's other lines stay.
- **Validation messages** are in `lang/am/validation.php` for the rules the panel uses. Any rule without one falls back to English.
- **The domains' refusals** (`MembershipRefused`, `PaymentRefused` and the rest) are written with `__()` and placeholders, so the panel shows them in Amharic and the API in English.
  - `InvalidDiscount` is the exception: the `Discount` value object runs without Laravel. It keeps an English message, plus its `text` and `values`, and the panel shows `__($exception->text, $exception->values)`.

### Dates

`App\Domains\Shared\Dates\ReadableDate` writes dates in either language; the panel reaches it through `App\Filament\Staff\Support\Dates`, which adds the staff member's language and the gym's time zone.

| Where | Amharic | English |
|---|---|---|
| Sentences, messages, details | ኅዳር 18 ቀን 2019 ዓ.ም. (ኖቬምበር 27, 2026) | 27 November 2026 |
| Tables | ኅዳር 18, 2019 (27/11/2026) | 27 Nov 2026 |
| Days close at hand | ዓርብ፣ ኅዳር 18 (27/11) | Fri 27 Nov |
| Insight charts (by calendar month) | ኖቬምበር 2026 | Nov 2026 |
| SMS | ኅዳር 18 ቀን 2019 ዓ.ም. | 27 November 2026 |

- **Ethiopian months** are written in Ge'ez (`EthiopianDate::monthNameIn()`), so yearly closures read ጥር 11.
- **Gregorian month names.** Carbon's Amharic ones are replaced when the app starts, written in full and correctly spelled. This also fixes Filament's own dates.
- **Date pickers** stay Gregorian, as Filament has none for the Ethiopian calendar. Each shows the Ethiopian date of the day chosen beneath it.
- **Messages** keep their dates as `2026-11-27` in the stored details. `MessageText` writes them out for the reader's language and channel, with the Ethiopian date alone in an SMS to keep texts short.

### Keeping it complete

These tests make a missing or English text fail CI:
- **`tests/Feature/Lang/AmharicPagesTest.php`** renders every list, form, view and custom page and every widget for an Amharic owner. It fails on any word in Latin letters that isn't the gym's own data or one of the few names kept as they are (CSV, QR).
- **`tests/Feature/Lang/AmharicPanelTextTest.php`** checks:
  - **text in the code:** every `__('…')` and `trans_choice('…')` text in `app/Filament`, `app/Insights` and the domains' exceptions is in `lang/am.json`;
  - **runtime labels:** so are labels worked out as the panel runs, such as resource, page and chart labels, every badge, check-in reason and insight;
  - **list names:** every list's Amharic name and plural has no Latin letter;
  - **placeholders:** each Amharic entry has the same placeholders as its English;
  - **lang file names:** no text is named like a lang file (`SMS` and `lang/en/sms.php`), because `__()` would return that whole file.

## Consequences

- **New text.** Every new panel or message text needs its Amharic in the same change, in the glossary's words, or CI fails. A fluent speaker reviews new Amharic, as with messages (ADR 0034).
- **Changing English.** The English text is the key, so changing it means changing its key in `lang/am.json` too. The test points out the missing one.
- **Filament upgrades** may add lines with no Amharic. The page test fails on any that staff see, and they go in `lang/vendor`.
- **Message dates changed for everyone.** English messages now read "27 November 2026" instead of "2026-11-27", and the members app shows inbox texts as written.
- **What stays English:**
  - the API, including its refusals and validation messages;
  - CSV reports and insight files, whose columns stay English for spreadsheets and other tools;
  - the gym's own data (plan names, branch names, announcements);
  - the English sample beside the English choice on My notifications;
  - the sign-in page, shown before anyone is known.
- **ADR 0034's "the panel's own words stay English"** no longer holds; this ADR replaces that line.

### Deferred

- An Ethiopian date picker.
- A panel language separate from the message language, should staff ask for one.
- Amharic answers from the staff API for a future staff app, using `Accept-Language` as the members app does.
- Amharic column names in CSV files.
