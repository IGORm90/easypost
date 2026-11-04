<?php

namespace App\Services\Posts;

/**
 * How one channel's trip to the network ended, as far as the group is concerned.
 * Published: every segment went out. Retry: some or none did, and trying again
 * later may help. Failed: trying again will not help, so the segments that did
 * not go out are marked as errors.
 */
enum ChannelOutcomeKind: string
{
    case Published = 'published';
    case Retry = 'retry';
    case Failed = 'failed';
}
