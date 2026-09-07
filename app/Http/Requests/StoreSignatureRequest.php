<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/signatures. `content` is capped at 1000 characters
 * — a generic sanity limit, not tied to any one provider's per-post
 * character limit (those range from Bluesky's 300 to Telegram's 4096, see
 * ProviderCapabilities in app/Social/Providers/*). The signature is
 * appended to post content on the FRONTEND (Task 25), never here, so
 * that it visibly counts against whichever provider's remaining budget
 * is shown while composing — appending it server-side would let a post
 * silently exceed a provider's limit with no on-screen warning. This cap
 * exists purely so a signature itself cannot be absurdly long, not to
 * pre-guess any provider's budget.
 */
class StoreSignatureRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:1000'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
