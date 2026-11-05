<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\PanelUser;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);
});

it('sends visitors who are not signed in to the staff sign-in, then back to the docs', function () {
    $this->get(route('scramble.docs.ui'))
        ->assertRedirect(route('filament.staff.auth.login'));

    expect(session('url.intended'))->toBe(route('scramble.docs.ui'));

    $this->get(route('scramble.docs.document'))
        ->assertRedirect(route('filament.staff.auth.login'));
});

it('shows the docs to staff signed in to the panel', function () {
    $staff = Staff::factory()->for($this->gym)->create();

    $this->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel')
        ->get(route('scramble.docs.ui'))
        ->assertOk();
});

it('describes the API, and that its calls take a bearer token', function () {
    $staff = Staff::factory()->for($this->gym)->create();

    $document = $this->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel')
        ->get(route('scramble.docs.document'))
        ->assertOk()
        ->json();

    expect($document['info']['title'])->toBe('Gym Platform API')
        ->and($document['paths'])->toHaveKey('/v1/organizations/{organization}/members')
        ->and($document['components']['securitySchemes'])->toContain(['type' => 'http', 'scheme' => 'bearer'])
        ->and($document['security'])->not->toBeEmpty();
});

it('keeps out users who work at no gym, and staff who were archived', function () {
    $member = User::factory()->create();

    $this->actingAs(PanelUser::query()->findOrFail($member->id), 'staff_panel')
        ->get(route('scramble.docs.ui'))
        ->assertForbidden();

    $archived = Staff::factory()->for($this->gym)->create();
    $archived->delete();

    $this->actingAs(PanelUser::query()->findOrFail($archived->user_id), 'staff_panel')
        ->get(route('scramble.docs.document'))
        ->assertForbidden();
});
