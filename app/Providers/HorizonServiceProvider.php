<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Task 34. Deliberately does NOT call `parent::boot()` /
 * `parent::authorization()`: the vendor `HorizonApplicationServiceProvider`
 * registers `Horizon::auth` as
 * `Gate::check('viewHorizon', ...) || app()->environment('local')` — the
 * `|| local` half is exactly the gap the brief's third access-test case
 * (an unauthenticated visitor) exists to catch. This stack's only
 * environment IS `local` (see config/horizon.php's own docblock — one
 * docker-compose target, no separate production one yet), so that
 * fallback would make `/horizon` world-readable to anyone on the
 * network with no login at all, not merely "readable to a superadmin".
 * `Horizon::auth` is registered directly below instead, with no
 * environment-based bypass of any kind.
 *
 * Horizon's own `Authenticate` middleware (registered on every `horizon`
 * route regardless of session state — see that class) calls
 * `Horizon::check($request)`, which calls this closure unconditionally;
 * `$request->user()` is null for a request with no authenticated
 * session, which the `instanceof User` check below turns into `false`
 * (403 via ForbiddenException), not an accidental `true` from a loose
 * comparison — verified by `tests/Feature/HorizonAccessTest.php`'s three
 * cases (ordinary user, superadmin, unauthenticated).
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        Horizon::auth(function (Request $request) {
            $user = $request->user();

            return $user instanceof User && $user->is_superadmin;
        });
    }
}
