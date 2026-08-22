<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The instance-wide user list behind the admin screen (Task 1.7).
 *
 * Impersonation has existed since Task 10 with nothing to point it at: a
 * superadmin had to already know a user's id and POST to
 * /api/admin/impersonate/{user} by hand. This is the missing half.
 *
 * Not organization-scoped, and that is the point rather than an
 * oversight: choosing whom to impersonate means looking outside your own
 * workspace, so the route carries no `organization` middleware and User
 * has no tenant global scope to work around. `superadmin`
 * (EnsureSuperadmin) is the entire gate, and it reads the global
 * `users.is_superadmin` column — never OrganizationRole::SUPERADMIN,
 * which is a per-tenant role and grants nothing here.
 */
class AdminUserController extends Controller
{
    /**
     * GET /api/admin/users?page=&search= — 25 per page.
     *
     * `search` matches name or email. Shaped after
     * MediaController::index(), down to the ILIKE escaping: it is the
     * only other paginated index in this codebase, and a second dialect
     * of "search a text column" would be one more place for the escaping
     * to be forgotten.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('organizations')->orderBy('created_at');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            // Escapes ILIKE's own wildcard syntax (%, _) and the escape
            // character itself, so a term containing them is matched
            // literally. Unescaped, a single '%' would match every row —
            // the search box would quietly stop filtering at the exact
            // moment someone typed a punctuation-heavy name.
            $escaped = addcslashes($search, '%_\\');

            $query->where(function ($builder) use ($escaped) {
                $builder->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('email', 'ilike', '%'.$escaped.'%');
            });
        }

        $users = $query->paginate(25)->withQueryString();

        return AdminUserResource::collection($users)->response();
    }
}
