<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportMediaFromUrlRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\Organization;
use App\Services\Media\Exceptions\MediaImportException;
use App\Services\Media\Exceptions\MediaInUseException;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\Exceptions\UnsupportedMediaTypeException;
use App\Services\Media\MediaUploadService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * HTTP surface for the media library (Task 27). Pure orchestration —
 * mime/URL validation and every storage decision live in
 * MediaUploadService, responses only through MediaResource.
 *
 * `{id}`-scoped actions resolve via `Media::query()->findOrFail()` —
 * never `withoutGlobalScopes()` — so BelongsToOrganization's global
 * scope confines the lookup to the current organization; a
 * wrong-organization id 404s exactly like a nonexistent one, same
 * pattern as IntegrationController/SetController.
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $media,
    ) {}

    public function store(UploadMediaRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $media = $this->media->store($file, $this->currentOrganization(), $this->currentUserId());
        } catch (UnsupportedMediaTypeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MediaStorageException $e) {
            // Review finding 1: a storage failure is loud, not a
            // silent 201 — see MediaStorageException's docblock. 500,
            // not 422: this is this application's/its infrastructure's
            // fault, not a problem with the caller's input.
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // ->response(), not response()->json(new MediaResource(...)):
        // JsonResource::jsonSerialize() (what response()->json() would
        // fall back to) returns the bare attribute array with no "data"
        // wrapper -- only toResponse()/response() apply
        // JsonResource::$wrap. Kept consistent with index() below.
        return (new MediaResource($media))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function importFromUrl(ImportMediaFromUrlRequest $request): JsonResponse
    {
        try {
            $media = $this->media->storeFromUrl(
                $request->string('url')->toString(),
                $this->currentOrganization(),
                $this->currentUserId(),
            );
        } catch (MediaImportException|UnsupportedMediaTypeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MediaStorageException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return (new MediaResource($media))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/media?page=&search= — 24 per page (Task 27 brief).
     * `search` matches against `original_filename` only; scoping to the
     * current organization comes from Media's own BelongsToOrganization
     * global scope, same as every other tenant-scoped index() in this
     * codebase.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Media::query()->orderByDesc('created_at');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            // Escapes ILIKE's own wildcard syntax (%, _) and the escape
            // character itself so a search term containing them is
            // matched literally, not interpreted as a pattern.
            $escaped = addcslashes($search, '%_\\');
            $query->where('original_filename', 'ilike', '%'.$escaped.'%');
        }

        $media = $query->paginate(24)->withQueryString();

        return MediaResource::collection($media)->response();
    }

    /**
     * Illuminate\Http\Response and Illuminate\Http\JsonResponse are
     * siblings (both extend their own distinct Symfony base), not
     * parent/child — an honest union return type here, since this is
     * the one destroy() in the codebase that returns a JSON error body
     * (409) instead of only ever letting an exception propagate to the
     * global handler.
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $media = Media::query()->findOrFail($id);
        Gate::authorize('delete', $media);

        try {
            $this->media->delete($media);
        } catch (MediaInUseException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->noContent();
    }

    private function currentOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = OrganizationContext::get();

        return $organization;
    }

    private function currentUserId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
