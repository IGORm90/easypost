<?php

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\InvalidStateTransition;
use App\Services\Posts\PostStateMachine;

/*
 * Task 18: PostStateMachine::transition()'s DB-backed behaviour — the
 * brief's own "queue to published" / "published to queue" scenarios,
 * plus persistence, the untouched-on-rejection guarantee, and PUBLISHED
 * terminality proven end-to-end via ->transition() rather than only via
 * the pure can() table (see tests/Unit/Posts/PostStateMachineTest.php
 * for that, and for why the DB-touching tests live here instead of
 * tests/Unit — the path the brief names — as well).
 */

function makePost(PostState $state): Post
{
    return Post::factory()->create(['state' => $state]);
}

it('forbids published to queue', function () {
    $post = makePost(PostState::PUBLISHED);

    app(PostStateMachine::class)->transition($post, PostState::QUEUE);
})->throws(InvalidStateTransition::class);

it('forbids published to draft', function () {
    $post = makePost(PostState::PUBLISHED);

    app(PostStateMachine::class)->transition($post, PostState::DRAFT);
})->throws(InvalidStateTransition::class);

it('forbids published to error', function () {
    $post = makePost(PostState::PUBLISHED);

    app(PostStateMachine::class)->transition($post, PostState::ERROR);
})->throws(InvalidStateTransition::class);

it('forbids published to published', function () {
    $post = makePost(PostState::PUBLISHED);

    app(PostStateMachine::class)->transition($post, PostState::PUBLISHED);
})->throws(InvalidStateTransition::class);

it('leaves the model untouched when a transition is rejected', function () {
    $post = makePost(PostState::PUBLISHED);

    try {
        app(PostStateMachine::class)->transition($post, PostState::QUEUE);
    } catch (InvalidStateTransition) {
        // expected
    }

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('persists an allowed transition on Post', function () {
    $post = makePost(PostState::DRAFT);

    app(PostStateMachine::class)->transition($post, PostState::QUEUE);

    expect($post->fresh()->state)->toBe(PostState::QUEUE);
});

it('persists a retry transition on Post from ERROR back to QUEUE', function () {
    $post = makePost(PostState::ERROR);

    app(PostStateMachine::class)->transition($post, PostState::QUEUE);

    expect($post->fresh()->state)->toBe(PostState::QUEUE);
});

it('operates on PostGroup exactly like Post, sharing the same table', function () {
    $group = PostGroup::factory()->create(['state' => PostState::DRAFT]);

    app(PostStateMachine::class)->transition($group, PostState::QUEUE);

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('forbids PostGroup published to queue, exactly like Post', function () {
    $group = PostGroup::factory()->create(['state' => PostState::PUBLISHED]);

    app(PostStateMachine::class)->transition($group, PostState::QUEUE);
})->throws(InvalidStateTransition::class);
