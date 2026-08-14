<?php

use App\Models\User;

beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('updates the profile fields', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'timezone' => 'UTC', 'locale' => 'en']);

    $response = $this->actingAs($user)->putJson('/api/profile', [
        'name' => 'New Name',
        'timezone' => 'Europe/Moscow',
        'locale' => 'ru',
        'email_notifications' => false,
    ])->assertOk();

    $response->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('user.timezone', 'Europe/Moscow')
        ->assertJsonPath('user.locale', 'ru')
        ->assertJsonPath('user.email_notifications', false);

    $user->refresh();
    expect($user->name)->toBe('New Name');
    expect($user->timezone)->toBe('Europe/Moscow');
    expect($user->locale)->toBe('ru');
    expect($user->email_notifications)->toBeFalse();
});

it('never returns the api key in the profile response', function () {
    $user = User::factory()->create(['api_key' => hash('sha256', 'secret')]);

    $response = $this->actingAs($user)->putJson('/api/profile', [
        'name' => $user->name,
        'timezone' => 'UTC',
        'locale' => 'en',
        'email_notifications' => true,
    ])->assertOk();

    $response->assertJsonMissingPath('user.api_key');
});

it('rejects a timezone that is not a real IANA identifier', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/profile', [
        'name' => $user->name,
        'timezone' => 'Not/A_Real_Zone',
        'locale' => 'en',
        'email_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('timezone');
});

it('rejects a locale outside the supported set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/profile', [
        'name' => $user->name,
        'timezone' => 'UTC',
        'locale' => 'fr',
        'email_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('locale');
});

it('requires authentication to update the profile', function () {
    $this->putJson('/api/profile', [
        'name' => 'Someone',
        'timezone' => 'UTC',
        'locale' => 'en',
        'email_notifications' => true,
    ])->assertUnauthorized();
});

it('rotates the api key, changing its stored value and returning the plaintext once', function () {
    $user = User::factory()->create(['api_key' => null]);

    $first = $this->actingAs($user)->postJson('/api/profile/api-key/rotate')->assertOk();
    $firstPlaintext = $first->json('api_key');
    expect($firstPlaintext)->toBeString();
    expect($firstPlaintext)->not->toBeEmpty();

    $user->refresh();
    expect($user->api_key)->not->toBeNull();
    // The stored value is never the plaintext itself.
    expect($user->api_key)->not->toBe($firstPlaintext);
    expect($user->api_key)->toBe(hash('sha256', $firstPlaintext));
    $firstHash = $user->api_key;

    $second = $this->actingAs($user)->postJson('/api/profile/api-key/rotate')->assertOk();
    $secondPlaintext = $second->json('api_key');

    expect($secondPlaintext)->not->toBe($firstPlaintext);

    $user->refresh();
    // The old hash no longer matches anything: rotation invalidated it.
    expect($user->api_key)->not->toBe($firstHash);
    expect($user->api_key)->toBe(hash('sha256', $secondPlaintext));
});

it('never exposes the api key through /api/auth/me', function () {
    $user = User::factory()->create(['api_key' => hash('sha256', 'secret')]);

    $this->actingAs($user)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonMissingPath('user.api_key');
});

it('requires authentication to rotate the api key', function () {
    $this->postJson('/api/profile/api-key/rotate')->assertUnauthorized();
});
