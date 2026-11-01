<?php

use App\Jobs\PublishPostJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

/*
 * A queued class that never names its queue lands on `default`, and one
 * that names a queue no Horizon supervisor consumes is never run at all —
 * silently, until a customer notices a post that did not go out. Both
 * failures are invisible to every other test, so this one enumerates every
 * queued class in app/Jobs and app/Notifications, builds it the way
 * production does (constructor included — that is where the queue is
 * assigned, see PublishPostJob) and asserts its queue is set explicitly
 * and is one a supervisor in config/horizon.php actually listens on.
 *
 * A new job without a queue fails here; that is the point.
 */

/** @return list<string> */
function supervisedQueues(): array
{
    /** @var array<string, array{queue: list<string>}> $supervisors */
    $supervisors = config('horizon.environments.*');

    $queues = [];
    foreach ($supervisors as $supervisor) {
        foreach ($supervisor['queue'] as $queue) {
            $queues[$queue] = $queue;
        }
    }

    return array_values($queues);
}

/** @return list<class-string<ShouldQueue>> */
function queuedClasses(): array
{
    $classes = [];
    foreach (['Jobs', 'Notifications'] as $dir) {
        // __DIR__, not app_path(): Pest evaluates a `with()` closure before the
        // application boots, when the container has no path() yet.
        foreach (glob(dirname(__DIR__, 3)."/app/{$dir}/*.php") ?: [] as $file) {
            $class = "App\\{$dir}\\".basename($file, '.php');
            if (in_array(ShouldQueue::class, class_implements($class) ?: [], true)) {
                $classes[] = $class;
            }
        }
    }

    return $classes;
}

/**
 * Builds the class from its own constructor signature so the test needs no
 * per-class fixture. An unrecognised parameter type throws, so a new job
 * with an exotic constructor makes this helper the thing that is updated,
 * not a reason to skip the job.
 *
 * @param  class-string  $class
 * @return object{queue: string|null}
 */
function makeQueued(string $class): object
{
    $args = [];
    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
        $type = $p->getType() instanceof ReflectionNamedType ? $p->getType()->getName() : null;

        $args[] = match (true) {
            $type === 'string' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $type === 'int' => 1,
            $type === 'bool' => false,
            $type !== null && is_subclass_of($type, Model::class) => new $type,
            default => throw new LogicException("Cannot build {$class}::\${$p->getName()} ({$type}) for JobQueuesTest."),
        };
    }

    return new $class(...$args);
}

it('finds the queued classes it is meant to guard', function () {
    expect(queuedClasses())->toContain(PublishPostJob::class)->and(queuedClasses())->not->toBeEmpty();
});

it('gives every queued class an explicit queue that a supervisor consumes', function (string $class) {
    $queue = makeQueued($class)->queue;

    expect($queue)->not->toBeNull("{$class} sets no queue and would silently run on `default`.");
    expect(in_array($queue, supervisedQueues(), true))
        ->toBeTrue("{$class} uses queue `{$queue}`, which no Horizon supervisor consumes.");
})->with(fn () => array_combine(queuedClasses(), array_map(fn ($c) => [$c], queuedClasses())));

it('sends PublishPostJob to the publish queue', function () {
    expect(makeQueued(PublishPostJob::class)->queue)->toBe('publish');
});

it('has a wait threshold for every supervised queue', function () {
    /** @var array<string, int> $waits */
    $waits = config('horizon.waits');

    foreach (supervisedQueues() as $queue) {
        expect(array_key_exists("redis:{$queue}", $waits))->toBeTrue("`{$queue}` has no waits threshold.");
    }
});
