<?php

namespace App\Http\Requests;

use App\Enums\PostState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates GET /api/posts (Block 2) — the paginated list view.
 *
 * No verb prefix in the name, unlike StorePostRequest/UpdatePostRequest:
 * those prefixes mark the writing requests. This is a read, same as
 * CalendarRequest/PostErrorsRequest/FindSlotRequest.
 *
 * `tags[]`/`customer` are deliberately left unchecked for existence, and
 * carry the exact names GET /api/calendar already uses: the grid/list
 * toggle sits on one screen and shares those two filter controls, so a
 * caller holding calendar filter state can reuse it here verbatim.
 * CalendarService resolves `tags[]` against the current organization's
 * own Tag rows (see its docblock for why a foreign id must yield zero
 * results rather than a 422 or a silently dropped filter).
 *
 * `state` accepts 'all' plus every PostState case, and the case list is
 * DERIVED from the enum rather than spelled out — a new state cannot be
 * forgotten here. An unknown value is a 422 on purpose: falling back to
 * "no filter" would answer a question the caller did not ask, and the one
 * screen this feeds is the one people use to find posts that failed.
 *
 * `per_page`'s ceiling is a validation rule, not a clamp: `?per_page=100000`
 * must be refused, not quietly turned into a request for a hundred
 * thousand rows plus their eager-loaded posts, channels and tags.
 */
class PostListRequest extends FormRequest
{
    /** Rows per page when the caller does not say. */
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

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
            'state' => ['sometimes', 'string', Rule::in(['all', ...array_column(PostState::cases(), 'value')])],
            'customer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * The requested state, or null for "every state" — both an absent
     * parameter and the explicit 'all' the SPA sends for its All segment.
     */
    public function state(): ?PostState
    {
        $state = $this->string('state')->toString();

        return $state === '' || $state === 'all' ? null : PostState::from($state);
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', self::DEFAULT_PER_PAGE);
    }
}
