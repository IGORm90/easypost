<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/calendar. `tags[]`/`customer` are left unchecked for
 * existence here on purpose — CalendarService resolves `tags[]` against
 * the current organization's own `Tag` rows itself (see its docblock for
 * why a foreign-organization id must yield zero results, not a 422 or a
 * silently-ignored filter), and `customer` is a free-text match against
 * `integrations.customer_name`, not a foreign key.
 */
class CalendarRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'customer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
