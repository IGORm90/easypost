<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $service) {}

    /**
     * Always returns the same message/status regardless of whether the
     * email is registered — see PasswordResetService::sendResetLink().
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->sendResetLink($request->string('email')->toString(), $request->ip() ?? '');

        return response()->json(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
