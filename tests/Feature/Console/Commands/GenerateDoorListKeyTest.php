<?php

declare(strict_types=1);

it('prints a new signing key for the server and its public key for the staff app', function () {
    $this->artisan('door-list:generate-key')
        ->expectsOutputToContain('DOOR_LIST_SIGNING_KEY=')
        ->expectsOutputToContain('Give this to the staff app')
        ->assertSuccessful();
});
