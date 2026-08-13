<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $service) {}

    public function verify(string $id, string $hash): JsonResponse
    {
        $this->service->verify($id, $hash);

        return response()->json(['message' => 'Email verified.']);
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->service->resend($user, $request->ip() ?? '');

        return response()->json(['message' => 'Verification email sent.']);
    }
}
