<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/posts/errors (Task 32) — the failed-publications
 * feed a calendar UI banner reads. Same `from`/`to` shape and rules as
 * CalendarRequest, deliberately: this is filtered by the same
 * `publish_date` column the calendar itself renders by, so a caller
 * already holding a calendar date range can reuse it verbatim here.
 */
class PostErrorsRequest extends FormRequest
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
        ];
    }
}
