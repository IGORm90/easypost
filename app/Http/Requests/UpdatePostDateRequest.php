<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/posts/{group}/date — the drag-and-drop endpoint
 * (task-20 brief). `after:now` rejects a past slot outright, regardless
 * of the group's current state: dropping a calendar card onto a slot
 * that has already passed can never be a legitimate action, scheduled or
 * still a draft. Whether the group may be moved at all (not PUBLISHED,
 * no already-released segment) is PostGroupService::guardEditable()'s
 * job, not this class's — it needs the resolved PostGroup, which
 * PostController loads and authorizes first.
 */
class UpdatePostDateRequest extends FormRequest
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
            'publish_date' => ['required', 'date', 'after:now'],
        ];
    }
}
