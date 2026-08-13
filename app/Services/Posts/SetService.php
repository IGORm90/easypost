<?php

namespace App\Services\Posts;

use App\Models\Set;

/**
 * Owns every write to `Set` (Task 23). Deliberately thin: ownership of
 * every `integration_ids` entry is already enforced by
 * StoreSetRequest/UpdateSetRequest before this runs, and
 * `organization_id` scoping/auto-fill comes from BelongsToOrganization —
 * there is no business rule left for this class to own beyond mapping
 * the validated `integration_ids` array into the `content` jsonb column.
 */
class SetService
{
    /**
     * @param  array{name: string, integration_ids: list<string>}  $data
     */
    public function create(array $data): Set
    {
        return Set::create([
            'name' => $data['name'],
            'content' => ['integration_ids' => $data['integration_ids']],
        ]);
    }

    /**
     * @param  array{name: string, integration_ids: list<string>}  $data
     */
    public function update(Set $set, array $data): Set
    {
        $set->fill([
            'name' => $data['name'],
            'content' => ['integration_ids' => $data['integration_ids']],
        ]);
        $set->save();

        return $set;
    }

    public function delete(Set $set): void
    {
        $set->delete();
    }
}
