<?php

use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Services\Posts\PostChainValidator;
use App\Support\OrganizationContext;
use Illuminate\Support\Str;

/*
 * PostChainValidator: everything about a post payload that Laravel's structural
 * rules cannot say (docs/plans/architecture-deepening.md, candidate 4) — the
 * publish date, the recurrence rule, and each channel's own limits, with the
 * media the payload references. It takes the payload array and returns the
 * errors as an ordered list of {field, message}, so the request is a thin
 * adapter and the rules are testable without HTTP. The structural rules
 * (shape, ownership of ids) stay in the request: the validator is only called
 * once those have passed, and trusts them.
 *
 * Runs for a signed-in tenant: integrations and media are looked up through the
 * 'organization' scope, so the context is set here the way the middleware sets
 * it for a request.
 */

beforeEach(fn () => OrganizationContext::set(Organization::factory()->create()));
afterEach(fn () => OrganizationContext::clear());

function chainOrg(): Organization
{
    $org = OrganizationContext::get();
    assert($org !== null);

    return $org;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chainPayload(array $overrides = []): array
{
    $integration = Integration::factory()->for(chainOrg())->create(['provider_identifier' => 'bluesky']);

    return [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'hello']]]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 * @return list<array{field: string, message: string}>
 */
function chainErrors(array $payload): array
{
    return app(PostChainValidator::class)->validate($payload);
}

// --- the publish date -----------------------------------------------------------

it('accepts a valid payload with no errors', function () {
    expect(chainErrors(chainPayload()))->toBe([]);
});

it('requires a publish date when scheduling', function () {
    expect(chainErrors(chainPayload(['type' => 'schedule'])))->toBe([
        ['field' => 'publish_date', 'message' => 'The publish date is required when scheduling a post.'],
    ]);
});

it('refuses a publish date in the past when scheduling', function () {
    expect(chainErrors(chainPayload(['type' => 'schedule', 'publish_date' => now()->subMinute()->toIso8601String()])))->toBe([
        ['field' => 'publish_date', 'message' => 'The publish date must be in the future.'],
    ]);
});

it('accepts a future publish date when scheduling', function () {
    expect(chainErrors(chainPayload(['type' => 'schedule', 'publish_date' => now()->addHour()->toIso8601String()])))->toBe([]);
});

it('does not look at the publish date for a draft or a post published now', function (string $type) {
    expect(chainErrors(chainPayload(['type' => $type, 'publish_date' => now()->subYear()->toIso8601String()])))->toBe([]);
})->with(['draft', 'now']);

// --- the recurrence rule --------------------------------------------------------

it('has nothing to say when there is no recurrence rule, or the unit is not one it knows', function (mixed $unit) {
    expect(chainErrors(chainPayload(['repeat_unit' => $unit, 'repeat_interval' => 99999, 'repeat_until' => now()->subYears(5)->toIso8601String()])))->toBe([]);
})->with([
    'none' => [null],
    'an unknown unit' => ['FORTNIGHT'],
]);

it('refuses an interval beyond what the unit allows', function (RepeatUnit $unit) {
    $max = $unit->maxInterval();

    expect(chainErrors(chainPayload(['repeat_unit' => $unit->value, 'repeat_interval' => $max + 1])))->toBe([
        ['field' => 'repeat_interval', 'message' => "The repeat interval may not be greater than {$max} for this unit."],
    ]);
})->with([RepeatUnit::DAY, RepeatUnit::WEEK, RepeatUnit::MONTH]);

it('accepts an interval at the unit\'s limit', function () {
    expect(chainErrors(chainPayload(['repeat_unit' => 'WEEK', 'repeat_interval' => RepeatUnit::WEEK->maxInterval(), 'repeat_until' => now()->addMonth()->toIso8601String()])))->toBe([]);
});

it('refuses an end date in the past', function () {
    expect(chainErrors(chainPayload(['repeat_unit' => 'DAY', 'repeat_interval' => 1, 'repeat_until' => now()->subDay()->toIso8601String()])))->toBe([
        ['field' => 'repeat_until', 'message' => 'The repeat end date must be in the future.'],
    ]);
});

it('refuses an end date more than a year away', function () {
    expect(chainErrors(chainPayload(['repeat_unit' => 'DAY', 'repeat_interval' => 1, 'repeat_until' => now()->addYear()->addDay()->toIso8601String()])))->toBe([
        ['field' => 'repeat_until', 'message' => 'The repeat end date may not be more than a year away.'],
    ]);
});

it('refuses an end date that is not after the publish date', function () {
    $errors = chainErrors(chainPayload([
        'type' => 'schedule',
        'publish_date' => now()->addDays(10)->toIso8601String(),
        'repeat_unit' => 'DAY',
        'repeat_interval' => 1,
        'repeat_until' => now()->addDays(5)->toIso8601String(),
    ]));

    expect($errors)->toBe([
        ['field' => 'repeat_until', 'message' => 'The repeat end date must be after the publish date.'],
    ]);
});

it('reports every recurrence problem, in the order it checks them', function () {
    $errors = chainErrors(chainPayload([
        'type' => 'draft',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_unit' => 'DAY',
        'repeat_interval' => RepeatUnit::DAY->maxInterval() + 1,
        'repeat_until' => now()->subDay()->toIso8601String(),
    ]));

    expect(array_column($errors, 'message'))->toBe([
        'The repeat interval may not be greater than 365 for this unit.',
        'The repeat end date must be in the future.',
        'The repeat end date must be after the publish date.',
    ]);
});

// --- each channel's own limits --------------------------------------------------

it('refuses content over the channel\'s character limit, on that segment\'s content field', function () {
    $payload = chainPayload();
    $payload['posts'][0]['value'] = [['content' => 'fine'], ['content' => str_repeat('a', 301)]];

    $errors = chainErrors($payload);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['field'])->toBe('posts.0.value.1.content')
        ->and($errors[0]['message'])->not->toBe('');
});

it('refuses too many images, on that segment\'s media_ids field', function () {
    $media = Media::factory()->for(chainOrg())->count(5)->create(['size_bytes' => 10_000]);
    $payload = chainPayload();
    $payload['posts'][0]['value'] = [['content' => 'pictures', 'media_ids' => $media->pluck('id')->all()]];

    $errors = chainErrors($payload);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['field'])->toBe('posts.0.value.0.media_ids');
});

it('reports an error that is about the segment as a whole on the segment itself', function () {
    // Instagram only publishes with media: "media required" is neither about the
    // text nor about any one file, so it is reported on the segment, not on a field of it.
    $instagram = Integration::factory()->for(chainOrg())->create(['provider_identifier' => 'instagram']);
    $payload = chainPayload();
    $payload['posts'] = [['integration_id' => $instagram->id, 'value' => [['content' => 'text only']]]];

    $errors = chainErrors($payload);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['field'])->toBe('posts.0.value.0');
});

it('counts an image the payload names once it exists, and ignores one it cannot find', function () {
    $media = Media::factory()->for(chainOrg())->count(4)->create(['size_bytes' => 10_000]);
    $payload = chainPayload();
    // Four real images (the limit) plus an id that resolves to nothing: still within the limit.
    $payload['posts'][0]['value'] = [['content' => 'pictures', 'media_ids' => [...$media->pluck('id')->all(), (string) Str::ulid()]]];

    expect(chainErrors($payload))->toBe([]);
});

it('numbers the channel by its position in the payload', function () {
    $second = Integration::factory()->for(chainOrg())->create(['provider_identifier' => 'bluesky']);
    $payload = chainPayload();
    $payload['posts'][] = ['integration_id' => $second->id, 'value' => [['content' => str_repeat('a', 301)]]];

    expect(array_column(chainErrors($payload), 'field'))->toBe(['posts.1.value.0.content']);
});

it('leaves an integration it cannot resolve in this organization to the request\'s own rules', function () {
    $foreign = Integration::factory()->for(Organization::factory()->create())->create(['provider_identifier' => 'bluesky']);
    $payload = chainPayload();
    $payload['posts'] = [
        ['integration_id' => $foreign->id, 'value' => [['content' => str_repeat('a', 301)]]],
        ['integration_id' => (string) Str::ulid(), 'value' => [['content' => str_repeat('a', 301)]]],
        ['integration_id' => 12345, 'value' => [['content' => str_repeat('a', 301)]]],
    ];

    expect(chainErrors($payload))->toBe([]);
});

it('reports the date, then the recurrence, then the channel limits', function () {
    $payload = chainPayload([
        'type' => 'schedule',
        'repeat_unit' => 'WEEK',
        'repeat_interval' => 99,
    ]);
    $payload['posts'][0]['value'] = [['content' => str_repeat('a', 301)]];

    expect(array_column(chainErrors($payload), 'field'))->toBe(['publish_date', 'repeat_interval', 'posts.0.value.0.content']);
});
