<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query string of
 * `GET /api/integrations/{id}/options/{option}` (Block 5.0.6).
 *
 * There is exactly one parameter and one guarantee: `context` is a flat
 * map of strings. That guarantee is worth a whole class because of where
 * the value goes — a provider builds an outbound request out of it (a
 * subreddit name becomes a path segment). Bounding it to one level of
 * strings means a provider can treat each value as a scalar it must
 * still encode, rather than as something whose type it has to check
 * first. Authorization stays with the controller, which resolves the
 * integration through the tenant-scoped policy the same way every other
 * action does.
 */
class ProviderOptionsRequest extends FormRequest
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
            'context' => ['sometimes', 'array'],
            // One level only: `context.*` matches a direct child, and
            // requiring it to be a string rejects `context[topic][x]=1`
            // (the child is an array) as well as any other nesting.
            'context.*' => ['string', 'max:255'],
        ];
    }

    /**
     * The validated context, always a map of strings — never null, so
     * callers have no "absent vs. empty" case to think about.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        /** @var array<string, string> $context */
        $context = $this->validated()['context'] ?? [];

        return $context;
    }
}
