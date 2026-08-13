<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSignatureRequest;
use App\Http\Requests\UpdateSignatureRequest;
use App\Http\Resources\SignatureResource;
use App\Models\Signature;
use App\Services\Posts\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * HTTP surface for organization-scoped signature CRUD (Task 23). Pure
 * orchestration — the single-default-per-organization invariant lives in
 * SignatureService, responses only through SignatureResource.
 *
 * `{signature}`-scoped actions resolve via
 * `Signature::query()->findOrFail()` — never `withoutGlobalScopes()` — so
 * a wrong-organization id 404s exactly like a nonexistent one, the same
 * pattern TagController/SetController document. No dedicated policy: the
 * `organization` route middleware already proves membership before this
 * controller runs.
 */
class SignatureController extends Controller
{
    public function __construct(
        private readonly SignatureService $signatures,
    ) {}

    public function index(): JsonResponse
    {
        $signatures = Signature::query()->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json(SignatureResource::collection($signatures));
    }

    public function store(StoreSignatureRequest $request): JsonResponse
    {
        $signature = $this->signatures->create($request->validated());

        return response()->json(new SignatureResource($signature), Response::HTTP_CREATED);
    }

    public function update(UpdateSignatureRequest $request, string $signature): JsonResponse
    {
        $signature = Signature::query()->findOrFail($signature);
        $signature = $this->signatures->update($signature, $request->validated());

        return response()->json(new SignatureResource($signature));
    }

    public function destroy(string $signature): Response
    {
        $signature = Signature::query()->findOrFail($signature);
        $this->signatures->delete($signature);

        return response()->noContent();
    }
}
