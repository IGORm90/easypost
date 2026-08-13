<?php

namespace App\Http\Requests;

use App\Http\Requests\Posts\Concerns\ValidatesPostChains;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates PUT /api/posts/{group} — a full replace of the group's
 * chains, same payload shape as POST (see ValidatesPostChains).
 *
 * What this class does NOT check: whether `{group}` may currently be
 * edited at all (a PUBLISHED group, or one with any already-released
 * segment, is immutable — see PostGroupService::guardEditable()) and
 * whether the requested `type` implies a state transition
 * PostStateMachine actually allows (e.g. QUEUE -> DRAFT is not a real
 * transition). Both require the *current* PostGroup, which this class
 * never loads — PostController resolves and authorizes it first; the
 * transition-sanity check lives in PostGroupService::resolveTargetState()
 * once that instance is in hand.
 */
class UpdatePostRequest extends FormRequest
{
    use ValidatesPostChains;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->chainRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyChainValidation($validator);
    }
}
