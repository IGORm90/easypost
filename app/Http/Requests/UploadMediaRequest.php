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
