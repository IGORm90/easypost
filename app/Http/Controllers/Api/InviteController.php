<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInviteRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\InviteService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * ADMIN+ only for the route itself (`role:ADMIN` middleware); minting
     * a SUPERADMIN invite additionally requires the actor to already be a
     * SUPERADMIN — see OrganizationPolicy::inviteAs(), enforced inside
     * InviteService::create(), not here.
     */
    public function store(CreateInviteRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var Organization $organization */
        $organization = OrganizationContext::get();
        $data = $request->validated();

        $invite = $this->invites->create(
            $organization,
            $actor,
            $data['email'] ?? null,
            OrganizationRole::from($data['role']),
        );

        return response()->json([
            'url' => $this->acceptUrl($invite->token),
        ], Response::HTTP_CREATED);
    }

    /**
     * No `organization`/`role` middleware: accepting an invite is how a
     * user gains their first (or an additional) membership, so it must
     * work with no X-Organization header and no existing membership at
     * all — only `auth:sanctum` gates this route.
     */
    public function accept(string $token): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $invite = $this->invites->accept($token, $user);

        return response()->json(['organization' => new OrganizationResource($invite->organization)]);
    }

    /**
     * Points at the SPA's own accept-invite route (Task 10 builds the
     * page), not at this API endpoint directly — accepting is a POST that
     * needs the invitee to be authenticated first, so the link must land
     * on a page, not trigger the action itself.
     */
    private function acceptUrl(string $token): string
    {
        return URL::to("/invites/{$token}");
    }
}
