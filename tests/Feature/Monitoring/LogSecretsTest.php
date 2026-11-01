<?php

use App\Models\Organization;
use App\Services\Posts\PublishService;
use Illuminate\Support\Facades\Log;

/*
 * A channel's token must reach no log sink — not on success, and not when
 * the provider's own error text echoes the credential back (some APIs put
 * the offending token in the message). RedactSensitiveContext redacts by
 * KEY name only, so a free-text message is the case it cannot see; this
 * drives the real publish pipeline into every sink at once and greps the
 * result for the actual secret values.
 */
/** Points every log channel at a fresh temp directory and returns it. */
function logSinks(): string
{
    $dir = sys_get_temp_dir().'/easypost-secrets-'.bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(static function () use ($dir) {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    });

    config([
        'logging.channels.viewer.with.filename' => "{$dir}/viewer.log",
        'logging.channels.app.with.stream' => "{$dir}/app.out",
        'logging.channels.jobs.with.stream' => "{$dir}/jobs.out",
        'logging.channels.errors.with.stream' => "{$dir}/errors.out",
        'logging.channels.stack.channels' => ['viewer', 'app', 'jobs', 'errors'],
        'logging.default' => 'stack',
        'logging.level' => 'debug',
    ]);
    Log::forgetChannel('stack');
    foreach (['viewer', 'app', 'jobs', 'errors'] as $channel) {
        Log::forgetChannel($channel);
    }

    return $dir;
}

function allLogOutput(string $dir): string
{
    return implode("\n", array_map(fn (string $f) => (string) file_get_contents($f), glob("{$dir}/*") ?: []));
}

it('never writes the channel token to any log sink on a successful publish', function () {
    $dir = logSinks();
    fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->subSeconds(5));
    [$integration] = publishableChannel($org, $group, ['hello']);

    app(PublishService::class)->publishGroup($group->id);

    $output = allLogOutput($dir);
    expect($output)->toContain('publish.attempt')
        ->and($output)->not->toContain($integration->token)
        ->and($output)->not->toContain($integration->refresh_token);
});

it('never writes the channel token to any log sink when the provider error echoes it', function () {
    $dir = logSinks();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->subSeconds(5));
    [$integration] = publishableChannel($org, $group, ['hello']);
    fakeProvider()->failNextPostPermanently("401 invalid token {$integration->token} for bot");

    app(PublishService::class)->publishGroup($group->id);

    $output = allLogOutput($dir);
    expect($output)->toContain('publish.attempt')
        ->and($output)->not->toContain($integration->token);
});
