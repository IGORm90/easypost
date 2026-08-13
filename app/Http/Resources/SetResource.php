<?php

namespace App\Http\Resources;

use App\Models\Integration;
use App\Models\Set;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Set
 *
 * `integration_ids` is NOT a straight pass-through of `content.integration_ids`
 * (the raw jsonb the model stores). A channel referenced by a set can later
 * be soft-deleted (Integration uses SoftDeletes) or belong to a different
 * organization if it were ever re-parented — this resource re-resolves the
 * stored ids against `Integration::query()` on every read, which is already
 * scoped to the current organization and excludes trashed rows via their
 * respective global scopes. A dead id therefore silently disappears from
 * the response instead of being handed to the Task 25 editor, which would
 * otherwise have to special-case "this channel id doesn't resolve to
 * anything" on every render. The stored `content` column itself is left
 * untouched by this filtering — it is a read-time projection only, not a
 * write-time prune — so a channel that gets re-enabled or "undeleted"
 * later reappears in the set automatically with no migration/backfill
 * needed. A merely *disabled* (not deleted) integration still resolves
 * here and is still returned: disabling is reversible and the id is still
 * a real, owned channel, so GET /api/integrations already carries the
 * `disabled` flag the editor needs to grey it out — dropping it here would
 * lose the set's intent, not protect the editor.
 */
class SetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<int, mixed> $storedIds */
        $storedIds = (array) ($this->content['integration_ids'] ?? []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'integration_ids' => Integration::query()
                ->whereIn('id', $storedIds)
                ->pluck('id')
                ->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
