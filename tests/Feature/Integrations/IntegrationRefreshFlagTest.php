<?php

use App\Models\Integration;
use App\Models\Organization;

/*
 * Integration::flagRefreshNeeded(): the one place a channel is marked as needing
 * its credentials re-connected after a publish found them rejected. A narrow
 * seam on purpose — the rest of an integration's health (when it clears, who else
 * sets it) is a separate piece of work, and this is the call it will replace.
 */

it('flags the channel for re-connection and saves it', function () {
    $integration = Integration::factory()->for(Organization::factory()->create())->create(['refresh_needed' => false]);

    $integration->flagRefreshNeeded();

    expect($integration->fresh()->refresh_needed)->toBeTrue();
});

it('changes nothing else about the channel, and is harmless to repeat', function () {
    $integration = Integration::factory()->for(Organization::factory()->create())->create([
        'refresh_needed' => false,
        'disabled' => false,
        'name' => 'my channel',
    ]);

    $integration->flagRefreshNeeded();
    $integration->flagRefreshNeeded();

    $fresh = $integration->fresh();
    expect($fresh->refresh_needed)->toBeTrue()
        ->and($fresh->disabled)->toBeFalse()
        ->and($fresh->name)->toBe('my channel');
});
