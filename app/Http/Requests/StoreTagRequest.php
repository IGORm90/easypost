<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTagUniqueness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/tags. `color` is a plain 6-digit hex string
 * (`#rrggbb`), never arbitrary text: Task 24 renders it straight into
 * HTML (e.g. as an inline style/background colour), so an unrestricted
 * string here would be an injection vector by the time it reaches that
 * task, not merely a cosmetic validation gap.
 */
class StoreTagRequest extends FormRequest
{
    use ValidatesTagUniqueness;

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
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
