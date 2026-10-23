<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Laravel\Sanctum\Sanctum;

it('requires a token', function () {
    $this->getJson(route('api.v1.me'))->assertUnauthorized();
});

it('returns the user and their role at every organization they work at', function () {
    $user = User::factory()->create();
    Staff::factory()->for($user)->for(Organization::factory()->state(['slug' => 'downtown-gym']))->owner()->create();
    Staff::factory()->for($user)->for(Organization::factory()->state(['slug' => 'uptown-gym']))->create(['role' => StaffRole::Trainer]);
    Staff::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.me'))->assertOk()->assertJsonPath('data.user.id', $user->id);

    $rolesBySlug = collect($response->json('data.staff'))
        ->mapWithKeys(fn (array $staff) => [$staff['organization']['slug'] => $staff['role']]);

    expect($rolesBySlug->all())->toEqual(['downtown-gym' => 'owner', 'uptown-gym' => 'trainer']);
});
