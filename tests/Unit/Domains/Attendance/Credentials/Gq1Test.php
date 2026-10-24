<?php

declare(strict_types=1);

use App\Domains\Attendance\Credentials\Gq1;

/*
 * The test vectors agreed with the members app (the Volt contract): the same
 * secret and credential must give the same codes in PHP and in Dart.
 */

const VOLT_SECRET_HEX = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
const VOLT_CREDENTIAL_ID = '01K7Z3M6Q8V2N4R5T9W1X0Y2AB';

it('makes the codes in the members app\'s test vectors', function (int $unixSeconds, int $step, string $code) {
    expect(Gq1::stepAt($unixSeconds))->toBe($step)
        ->and(Gq1::code((string) hex2bin(VOLT_SECRET_HEX), VOLT_CREDENTIAL_ID, $step))->toBe($code);
})->with([
    '2026-10-12T08:00:00Z' => [1791792000, 59726400, '5365BDE868B40851'],
    'the same step, 29 seconds later' => [1791792029, 59726400, '5365BDE868B40851'],
    'the next step' => [1791792030, 59726401, 'E3A7DC2867D3DA18'],
    'the last step of the UTC day' => [1791849599, 59728319, '2B9CBB9F484077FF'],
    'the first step of the next UTC day' => [1791849600, 59728320, '317000CADC5ED3B9'],
]);

it('derives the day keys in the test vectors', function (int $day, string $dayKey) {
    expect(bin2hex(Gq1::dayKey((string) hex2bin(VOLT_SECRET_HEX), $day)))->toBe($dayKey);
})->with([
    [20738, '89299dbf0a94121de26df334785f4965f39dc4d54f11ab57d8a957f5201895f7'],
    [20739, '7c36e6e8dd2d6e7bc65eb2680a2591e93dfe708ac120f311abf05789e8b65fa2'],
]);

it('writes and reads back the payload the QR code holds', function () {
    $payload = Gq1::payload((string) hex2bin(VOLT_SECRET_HEX), VOLT_CREDENTIAL_ID, 59726400);
    $parsed = Gq1::parse($payload);

    expect($payload)->toBe('GQ1:01K7Z3M6Q8V2N4R5T9W1X0Y2AB:59726400:5365BDE868B40851')
        ->and($parsed?->credentialId)->toBe(VOLT_CREDENTIAL_ID)
        ->and($parsed?->step)->toBe(59726400)
        ->and($parsed?->code)->toBe('5365BDE868B40851');
});

it('reads nothing from a code that is not GQ1', function (string $payload) {
    expect(Gq1::parse($payload))->toBeNull();
})->with([
    'another version' => ['GQ2:01K7Z3M6Q8V2N4R5T9W1X0Y2AB:59726400:5365BDE868B40851'],
    'a lowercase code' => ['GQ1:01K7Z3M6Q8V2N4R5T9W1X0Y2AB:59726400:5365bde868b40851'],
    'a short code' => ['GQ1:01K7Z3M6Q8V2N4R5T9W1X0Y2AB:59726400:5365BDE8'],
    'no step' => ['GQ1:01K7Z3M6Q8V2N4R5T9W1X0Y2AB::5365BDE868B40851'],
    'a member number' => ['12345'],
]);
