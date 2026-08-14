<?php

namespace App\Services\Posts;

use App\Models\Tag;

/**
 * Owns every write to `Tag` (Task 21). Deliberately thin: uniqueness
 * (case-insensitive per organization) and colour format are already
 * enforced by StoreTagRequest/UpdateTagRequest before this runs, and
 * `organization_id` scoping/auto-fill comes from BelongsToOrganization —
 * there is no business rule left for this class to own beyond the plain
 * CRUD calls.
 */
class TagService
{
    /**
     * @param  array{name: string, color: string}  $data
     */
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    /**
     * @param  array{name?: string, color?: string}  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->fill($data);
        $tag->save();

        return $tag;
    }

    /**
     * `post_group_tag` rows cascade at the database level (see the
     * migration's `cascadeOnDelete()`), so deleting a tag simply detaches
     * it from every group that carried it — no explicit sync/detach
     * needed here.
     */
    public function delete(Tag $tag): void
    {
        $tag->delete();
    }
}
