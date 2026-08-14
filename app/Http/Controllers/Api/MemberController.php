<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\MembershipService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MemberController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Any member of the current organization may list its teammates —
     * open to USER, not just ADMIN+ (unlike destroy()/updateRole()).
     * $organization->members() is a BelongsToMany from this specific
     * organization instance, so it is inherently scoped to it.
     */
    public function index(): JsonResponse
    {
        $organization = $this->currentOrganization();

        return response()->json(MemberResource::collection($organization->members()->get()));
    }

    /**
     * ADMIN+ only, enforced by the `role:ADMIN` route middleware. The
     * finer-grained "may $actor touch this specific member" rule (last
     * SUPERADMIN, SUPERADMIN-vs-SUPERADMIN) lives in MembershipService.
     */
    public function destroy(User $user): Response
    {
        /** @var User $actor */
        $actor = auth()->user();

        $this->memberships->remove($this->currentOrganization(), $actor, $user);

        return response()->noContent();
    }

    public function updateRole(UpdateMemberRoleRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = auth()->user();

        $membership = $this->memberships->updateRole(
            $this->currentOrganization(),
            $actor,
            $user,
            OrganizationRole::from($request->validated('role')),
        );

        return response()->json(['id' => $user->id, 'role' => $membership->role->value]);
    }

    private function currentOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = OrganizationContext::get();

        return $organization;
    }
}
