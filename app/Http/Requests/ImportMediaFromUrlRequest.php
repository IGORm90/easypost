<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/media/from-url. Deliberately shape-only (a
 * non-empty, bounded-length string) — Laravel's built-in `url` rule is
 * NOT used here, because whether this URL is actually safe to fetch
 * (scheme restricted to http/https, SSRF-blocked, resolved on every
 * redirect hop) is App\Support\SafeRemoteHost's job, called from
 * MediaUploadService::storeFromUrl(). Duplicating a shallower version of
 * that check here would risk a request that passes this layer's opinion
 * of "looks like a URL" but fails (or worse, is judged differently by) the
 * service's real check, or vice versa.
 */
class ImportMediaFromUrlRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
