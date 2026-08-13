<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profile) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->profile->update($user, $request->validated());

        return response()->json(['user' => new UserResource($user)]);
    }

    /**
     * `deny_while_impersonating` route middleware, not a check here, gates
     * this action while impersonating — see that middleware's docblock.
     */
    public function rotateApiKey(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $plaintext = $this->profile->rotateApiKey($user);

        return response()->json(['api_key' => $plaintext]);
    }
}
