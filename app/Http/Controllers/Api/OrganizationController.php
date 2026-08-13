<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * Lists only the organizations the authenticated user is a member of
     * — via User::organizations(), a BelongsToMany scoped to this specific
     * user, never a bare Organization::all().
     */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return response()->json(OrganizationResource::collection($user->organizations));
    }

    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $organization = $this->organizations->createForUser($user, $request->validated('name'));

        return response()->json(new OrganizationResource($organization), Response::HTTP_CREATED);
    }

    /**
     * ADMIN+ only — enforced by the `role:ADMIN` route middleware, run
     * before this action, not by an if-statement here.
     */
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = OrganizationContext::get();

        $organization = $this->organizations->updateSettings($organization, $request->validated());

        return response()->json(new OrganizationResource($organization));
    }
}
