<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Final review, Critical 6: every server-rendered message — validation
 * errors above all, which seven pages render verbatim out of
 * `errors[field][0]` — reached the user in English regardless of who they
 * were. APP_LOCALE is `en`, nothing ever called App::setLocale(), and
 * lang/ru held only mail.php. A Russian user filled a fully Russian
 * register form and got "The email has already been taken." underneath it.
 */

beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('renders validation errors in the signed-in user\'s stored locale', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    $this->actingAs($user)
        ->putJson('/api/profile', ['name' => '', 'timezone' => 'UTC', 'locale' => 'ru'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', __('validation.required', ['attribute' => 'name'], 'ru'));
});

it('renders validation errors in English for a user whose stored locale is English', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->putJson('/api/profile', ['name' => '', 'timezone' => 'UTC', 'locale' => 'en'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', __('validation.required', ['attribute' => 'name'], 'en'));
});

it('falls back to Accept-Language when there is no signed-in user', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);

    $this->withHeader('Accept-Language', 'ru-RU,ru;q=0.9,en;q=0.8')
        ->postJson('/api/auth/login', ['email' => 'ann@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', __('validation.required', ['attribute' => 'password'], 'ru'));
});

it('ignores an Accept-Language naming a locale this app has no messages for', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);

    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->postJson('/api/auth/login', ['email' => 'ann@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', __('validation.required', ['attribute' => 'password'], 'en'));
});

it('prefers the stored locale over Accept-Language — the profile setting is the explicit choice', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->putJson('/api/profile', ['name' => '', 'timezone' => 'UTC', 'locale' => 'ru'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', __('validation.required', ['attribute' => 'name'], 'ru'));
});
