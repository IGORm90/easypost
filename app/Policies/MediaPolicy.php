<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

/**
 * Authorization for the media library (Task 27). `MediaController`
 * resolves `{id}` via `Media::query()->findOrFail()` — never
 * `withoutGlobalScopes()` — so BelongsToOrganization's global scope
 * already confines the lookup to the current organization before this
 * policy is ever consulted; a wrong-organization id 404s exactly like a
 * nonexistent one, the same pattern SetController/TagController
 * document. Both abilities therefore only need to re-confirm plain
 * membership — the same bar Sets/Signatures/Tags set for "everyday
 * content work" — as a second, explicit check rather than relying
 * solely on the route middleware.
 */
class MediaPolicy
{
    public function view(User $user, Media $media): bool
    {
        return $media->organization->roleOf($user) !== null;
    }

    public function delete(User $user, Media $media): bool
    {
        return $media->organization->roleOf($user) !== null;
    }
}
