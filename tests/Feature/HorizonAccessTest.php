<?php

use App\Models\User;

/*
 * Task 34: `Horizon::auth` must gate `/horizon` on `is_superadmin` alone
 * — not on Horizon's own package default (`viewHorizon` gate empty-list
 * OR `app()->environment('local')`), which would let anyone in on a
 * developer's machine and nobody in anywhere else regardless of role.
 *
 * Three cases, per the brief: an ordinary authenticated user is
 * forbidden, a superadmin is let in, and — the case people get wrong,
 * because Horizon's own `Authenticate` middleware never requires a
 * logged-in user before consulting `Horizon::auth` — an unauthenticated
 * visitor is forbidden too, not silently 200'd because `$request->user()`
 * comes back null and the callback throws on that instead of allowing it.
 */
it('forbids an ordinary authenticated user from viewing horizon', function () {
    $user = User::factory()->create(['is_superadmin' => false]);

    $this->actingAs($user)
        ->get('/horizon/api/stats')
        ->assertForbidden();
});

it('lets a superadmin view horizon', function () {
    $superadmin = User::factory()->create(['is_superadmin' => true]);

    $this->actingAs($superadmin)
        ->get('/horizon/api/stats')
        ->assertOk();
});

it('forbids an unauthenticated visitor from viewing horizon', function () {
    $this->get('/horizon/api/stats')
        ->assertForbidden();
});
