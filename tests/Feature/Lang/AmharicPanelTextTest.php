<?php

declare(strict_types=1);

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Imports\Exceptions\RowRefused;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Widgets\Insights\BusyHoursByWeekdayChart;
use App\Insights\GymInsights;
use App\Insights\Insight;
use Filament\Facades\Filament;

/**
 * Every English text the panel translates with __() or trans_choice(), as
 * written in the code: the staff panel and what it shares with the admin
 * panel, the insights and the domains' refusals. The admin panel itself is
 * for the platform's own staff and stays English (ADR 0042). Keys of lang
 * files ("notifications.title") are left out.
 *
 * @return list<string>
 */
function panelTextsInTheCode(): array
{
    $texts = [];
    $folders = [app_path('Filament/Staff'), app_path('Filament/Concerns'), app_path('Insights'), ...glob(app_path('Domains/*/Exceptions')), ...glob(app_path('Domains/*/*/Exceptions'))];

    foreach ($folders as $folder) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $text) {
                $text = stripslashes($text);

                if (preg_match('/^[a-z_]+(\.[A-Za-z0-9_]+)+$/', $text) !== 1 && ! str_contains($text, '::')) {
                    $texts[] = $text;
                }
            }
        }
    }

    return array_values(array_unique($texts));
}

it('has Amharic for every text the panel shows', function () {
    $amharic = json_decode((string) file_get_contents(lang_path('am.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = array_values(array_filter(panelTextsInTheCode(), fn (string $text): bool => ! isset($amharic[$text]) || $amharic[$text] === ''));

    expect($missing)->toBe([]);
});

it('has Amharic for the labels Filament makes from names', function () {
    $amharic = json_decode((string) file_get_contents(lang_path('am.json')), true, flags: JSON_THROW_ON_ERROR);
    $labels = [
        // Tables::badge() and Tables::money() labels made from a column's name.
        'Method', 'Result', 'Role', 'Status', 'Type', 'Price',
    ];

    foreach (Filament::getPanel('staff')->getResources() as $resource) {
        $labels[] = $resource::getModelLabel();
        $labels[] = $resource::getPluralModelLabel();
        $labels[] = $resource::getNavigationLabel();
    }

    expect(array_values(array_unique(array_filter($labels, fn (string $label): bool => ! isset($amharic[$label])))))->toBe([]);
});

it('keeps each Amharic text\'s placeholders', function () {
    $amharic = json_decode((string) file_get_contents(lang_path('am.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($amharic as $english => $translation) {
        preg_match_all('/:[a-z_]+/', $english, $expected);
        preg_match_all('/:[a-z_]+/', $translation, $actual);
        $expected = array_unique($expected[0]);
        $actual = array_unique($actual[0]);
        sort($expected);
        sort($actual);

        expect($actual)->toBe($expected, "Placeholders of \"{$english}\"");
    }
});

it('has no text named like a lang file, which __() would answer with the whole file', function () {
    $amharic = json_decode((string) file_get_contents(lang_path('am.json')), true, flags: JSON_THROW_ON_ERROR);
    $files = array_map(fn (string $path): string => strtolower(basename($path, '.php')), glob(lang_path('en/*.php')) ?: []);

    expect(array_values(array_filter(array_keys($amharic), fn (string $text): bool => in_array(strtolower($text), $files, true))))->toBe([]);
});

it('has Amharic for the labels the panel works out as it runs', function () {
    $amharic = json_decode((string) file_get_contents(lang_path('am.json')), true, flags: JSON_THROW_ON_ERROR);
    $panel = Filament::getPanel('staff');
    $labels = [GymInsights::NO_PLAN, ...BusyHoursByWeekdayChart::WEEKDAYS];

    foreach ([AnnouncementStatus::class, CheckInMethod::class, CheckInResult::class, ChapaMode::class, PaymentLinkStatus::class, PaymentMethod::class, PaymentType::class, MembershipStatus::class, StaffRole::class] as $enum) {
        foreach ($enum::cases() as $case) {
            $labels[] = Badges::label($case);
        }
    }

    foreach (CheckInDenial::cases() as $denial) {
        $labels[] = $denial->label();
    }

    // A discount the panel can't read, shown from its text (InvalidDiscount).
    foreach ([InvalidDiscount::cannotParse('x'), InvalidDiscount::notMoreThanZero('0'), InvalidDiscount::moreThanAHundredPercent('101%'), InvalidDiscount::moreThanThePrice(Money::of('100')), InvalidDiscount::nothingOff(Money::of('100'))] as $invalid) {
        $labels[] = $invalid->text;
    }

    // Why a row of a member import wasn't imported, shown from its text (RowRefused, ADR 0044).
    foreach ((new ReflectionClass(RowRefused::class))->getMethods(ReflectionMethod::IS_STATIC) as $factory) {
        $arguments = array_map(fn (ReflectionParameter $parameter): string|int => $parameter->getType()?->__toString() === 'int' ? 2 : 'x', $factory->getParameters());
        $labels[] = $factory->invoke(null, ...$arguments)->text;
    }

    foreach (Insight::cases() as $insight) {
        $labels[] = $insight->label();
        $labels[] = $insight->description();
    }

    // Page titles and subheadings, and chart headings and descriptions.
    foreach ([...$panel->getPages(), ...$panel->getWidgets()] as $class) {
        $defaults = (new ReflectionClass(is_string($class) ? $class : $class->widget))->getDefaultProperties();

        foreach (['title', 'subheading', 'heading', 'description'] as $property) {
            if (is_string($defaults[$property] ?? null)) {
                $labels[] = $defaults[$property];
            }
        }
    }

    expect(array_values(array_unique(array_filter($labels, fn (string $label): bool => $label !== '' && ! isset($amharic[$label])))))->toBe([]);
});

it('gives every list a real Amharic name and plural, never an English "s"', function () {
    app()->setLocale('am');

    $labels = [];

    foreach (Filament::getPanel('staff')->getResources() as $resource) {
        $labels[$resource] = [$resource::getModelLabel(), $resource::getPluralModelLabel(), $resource::getNavigationLabel()];
    }

    app()->setLocale('en');

    expect(array_filter($labels, fn (array $names): bool => preg_match('/[A-Za-z]/', implode(' ', $names)) === 1))->toBe([]);
});
