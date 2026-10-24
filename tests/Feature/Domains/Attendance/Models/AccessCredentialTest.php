<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('keeps the secret encrypted', function () {
    $credential = AccessCredential::factory()->create();

    expect(DB::table('access_credentials')->value('secret'))->not->toBe($credential->secret)
        ->and(strlen($credential->rawSecret()))->toBe(32);
});

it('refuses a second active credential for a member, but keeps revoked ones', function () {
    $credential = AccessCredential::factory()->create();
    AccessCredential::factory()->create([
        'member_id' => $credential->member_id,
        'revoked_at' => '2026-10-24 08:00:00',
        'revoked_reason' => CredentialRevocation::Replaced,
    ]);

    AccessCredential::factory()->create(['member_id' => $credential->member_id]);
})->throws(QueryException::class, 'violates unique constraint');

it('refuses a revocation without its reason', function () {
    AccessCredential::factory()->create(['revoked_at' => '2026-10-24 08:00:00']);
})->throws(QueryException::class, 'violates check constraint');
