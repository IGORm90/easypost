<?php

namespace App\Http\Requests;

use App\Services\Media\MediaUploadService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validates POST /api/media. `file` must be an actual uploaded file
 * whose SNIFFED content (MediaUploadService::sniffMime() — real
 * `finfo`, reading the temp file PHP itself already wrote to local disk
 * for this request, not the client-supplied `Content-Type` header and
 * not the filename) matches one of MediaUploadService::ALLOWED_MIME_TYPES.
 * The rejection message never echoes the client's original filename
 * back — only the sniffed mime — so a filename crafted to look
 * misleading in a validation error carries no extra information.
 *
 * Deliberately does not duplicate the mime allow-list here as a second,
 * independent literal: it calls MediaUploadService::sniffMime()
 * directly, the exact function MediaUploadService::store() uses to
 * decide what to persist, so this request and the service it feeds can
 * never validate against one mime and store as if it were another.
 */
class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Review finding 3 (Important): with no explicit size
                // rule here, an oversized direct upload was rejected
                // only by nginx's 1 MB default `client_max_body_size`
                // (fixed separately in docker/nginx/default.conf) — an
                // opaque HTML 413 the frontend cannot render, not a
                // validation error this request layer produces. `max`
                // is in kilobytes (Laravel convention); kept aligned
                // with config('media.import_max_bytes') — the same
                // 100 MB ceiling the from-url path already enforces —
                // rather than a second, independent literal.
                'max:'.intdiv((int) config('media.import_max_bytes', 100 * 1024 * 1024), 1024),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        $fail('The file is invalid.');

                        return;
                    }

                    $realPath = $value->getRealPath();

                    if ($realPath === false) {
                        $fail('The file could not be read.');

                        return;
                    }

                    $mime = MediaUploadService::sniffMime($realPath);

                    if (! array_key_exists($mime, MediaUploadService::ALLOWED_MIME_TYPES)) {
                        $fail("The file type [{$mime}] is not supported.");
                    }
                },
            ],
        ];
    }
}
