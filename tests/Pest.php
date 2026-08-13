<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function actingAsMember(Organization $org, string $role = 'ADMIN'): User
{
    $user = User::factory()->create();
    // Organization::addMember(), not members()->attach(): attach() inserts
    // through the query builder and never fills organization_user.id (no
    // DB default, HasUlids' creating hook never fires for it) — see
    // OrganizationUser's docblock.
    $org->addMember($user, OrganizationRole::from($role));
    test()->actingAs($user);
    test()->withHeader('X-Organization', $org->id);

    return $user;
}

/**
 * Runs each of $racers in its own forked OS process, genuinely
 * concurrently, and returns one outcome string per racer (same order):
 * 'ok' on success, or 'fail:<status>' if the racer threw.
 *
 * Why this exists: sequential Pest/HTTP calls — even several in a row —
 * can never reproduce a TOCTOU race, because each call's transaction
 * fully commits before the next one starts; there is never a moment
 * where two are genuinely in flight together. Proving a fix for that
 * class of bug (see InviteService::accept(), MembershipService's
 * last-SUPERADMIN guard) requires actual OS-level concurrency.
 *
 * Each racer runs in a forked child that switches Eloquent's default
 * connection to a brand-new, never-before-used connection name before
 * touching the database. This is required, not optional, and it is
 * deliberately NOT `DB::purge()`: fork() duplicates the parent's live PDO
 * *socket* into the child (both processes hold a file descriptor for the
 * same underlying TCP connection to the same Postgres backend). Disconnecting
 * — which is exactly what purge() does — sends a real wire-level
 * terminate message down that shared socket, killing the session for
 * *both* processes: the child's own attempt to reconnect races against
 * the parent (and every sibling child) still relying on that same
 * connection, and in practice reliably takes the whole test process down
 * with "server closed the connection unexpectedly" (verified by
 * construction — this is exactly what happened the first time this
 * helper called `DB::purge()` here). Pointing `database.default` at an
 * unused connection name instead means the inherited connection object is
 * simply never touched again in the child — no explicit disconnect, and
 * no risk of PHP's garbage collector tearing it down either, since
 * Laravel's connection resolver keeps it referenced for the process's
 * lifetime. The child is later terminated with SIGKILL (never a normal
 * PHP shutdown), so no destructor for that untouched connection ever runs
 * there either.
 *
 * The *parent* process (this test) never touches any of this — it only
 * forks, waits, and reads results back from disk — so RefreshDatabase's
 * transaction/rollback bookkeeping on its own default connection is
 * completely undisturbed.
 *
 * Because forked children start from a connection with no visibility
 * into the parent's still-open RefreshDatabase transaction, any row a
 * racer needs to see must be committed through a connection outside that
 * transaction before calling this — see raceCreate() below.
 *
 * @param  array<int, Closure(): void>  $racers
 * @return array<int, string>
 */
function raceConcurrently(array $racers): array
{
    $resultDir = sys_get_temp_dir().'/pest-race-'.bin2hex(random_bytes(6));
    mkdir($resultDir);

    $pids = [];

    foreach ($racers as $i => $racer) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed — cannot simulate concurrency.');
        }

        if ($pid === 0) {
            $childConnection = 'race_child_'.$i.'_'.getmypid();
            config(['database.connections.'.$childConnection => config('database.connections.pgsql')]);
            config(['database.default' => $childConnection]);

            $result = 'ok';

            try {
                $racer();
            } catch (Throwable $e) {
                $result = 'fail:'.raceOutcomeStatus($e);
            }

            file_put_contents("{$resultDir}/{$i}", $result);

            // SIGKILL, not exit(): avoids re-running any of the parent
            // test process's registered shutdown handlers (Pest/PHPUnit
            // teardown, coverage, etc.) a second time from this forked
            // copy of the process, and guarantees no destructor runs here
            // for the inherited-but-untouched original connection (see
            // above).
            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[$i] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];

    foreach (array_keys($racers) as $i) {
        $results[$i] = trim((string) file_get_contents("{$resultDir}/{$i}"));
    }

    foreach (glob("{$resultDir}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($resultDir);

    return $results;
}

function raceOutcomeStatus(Throwable $e): string
{
    if ($e instanceof HttpExceptionInterface) {
        return (string) $e->getStatusCode();
    }

    if ($e instanceof ValidationException) {
        return (string) $e->status;
    }

    return get_class($e);
}

/**
 * Creates and persists a model through the "race" connection (see
 * raceConcurrently()'s docblock) — a separate, plain-autocommit
 * connection to the same database, used so fixtures are visible to
 * forked child processes that cannot see anything still sitting inside
 * RefreshDatabase's open transaction on the default connection.
 *
 * @template TModel of Illuminate\Database\Eloquent\Model
 *
 * @param  class-string<TModel>  $class
 * @param  array<string, mixed>  $attributes
 * @return TModel
 */
function raceCreate(string $class, array $attributes)
{
    $model = new $class($attributes);
    $model->setConnection('race')->save();

    return $model;
}
