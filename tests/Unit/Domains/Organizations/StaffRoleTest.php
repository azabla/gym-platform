<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;

it('shows the money to owners, managers and accountants only', function (StaffRole $role, bool $seesMoney) {
    expect($role->seesMoney())->toBe($seesMoney);
})->with([
    'an owner' => [StaffRole::Owner, true],
    'a manager' => [StaffRole::Manager, true],
    'an accountant' => [StaffRole::Accountant, true],
    'a receptionist' => [StaffRole::Receptionist, false],
    'a trainer' => [StaffRole::Trainer, false],
]);
