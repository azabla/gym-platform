<?php

declare(strict_types=1);

use Filament\Facades\Filament;

/**
 * Every English text the panel translates with __() or trans_choice(), as
 * written in the code: the staff panel, the insights and the domains'
 * refusals. Keys of lang files ("notifications.title") are left out.
 *
 * @return list<string>
 */
function panelTextsInTheCode(): array
{
    $texts = [];
    $folders = [app_path('Filament'), app_path('Insights'), ...glob(app_path('Domains/*/Exceptions'))];

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
