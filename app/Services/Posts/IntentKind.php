<?php

namespace App\Services\Posts;

/**
 * What a caller wants done to a PostGroup. A closed vocabulary: the caller
 * states the intent and never a version number or a target state; the
 * lifecycle decides what that means for `queue_version`, the posts, and the
 * dispatch. Grows one case per write path as it moves into PostGroupLifecycle.
 */
enum IntentKind
{
    case Retry;
    case Reschedule;
}
