import type { ProviderCapabilities } from '../../stores/integrations';

export type { ProviderCapabilities };

/** One post/reply in a channel's thread — the exact shape `POST /api/posts`'s `posts[].value[]` accepts. */
export interface PostSegment {
    content: string;
    /** Always sent as `[]` from this task's UI — the media picker (Tasks 27-29) is what ever populates it. */
    media_ids: string[];
}

/** A channel-set, as `SetResource` (Task 23) shapes it. */
export interface SetDto {
    id: string;
    name: string;
    integration_ids: string[];
}

/** An organization-scoped signature, as `SignatureResource` (Task 23) shapes it. */
export interface SignatureDto {
    id: string;
    content: string;
    is_default: boolean;
}

export type SaveType = 'draft' | 'schedule' | 'now';

/**
 * One selected channel's editable state inside the editor. `signatureId`
 * is a purely client-side choice — the backend has no column recording
 * which signature (if any) produced a chain's stored content, so it is
 * never inferred back out of loaded content (see PostEditorModal.vue's
 * docblock) and always starts unset, even when editing an existing group.
 */
export interface ChannelDraft {
    segments: PostSegment[];
    signatureId: string | null;
    settings: Record<string, unknown>;
    /**
     * True once the user has typed inside this channel's own tab (or the
     * thread was loaded from an existing group). While false, the
     * channel's root segment mirrors the shared "default text" field
     * live; PostEditorModal.vue is the only place that reads/writes it.
     */
    dirty: boolean;
}

/** The shape `GET/POST/PUT /api/posts...` return — PostGroupResource, 1:1. */
export interface PostGroupApi {
    id: string;
    state: string;
    publish_date: string | null;
    error: string | null;
    share_token: string | null;
    tags: string[];
    posts: Array<{
        integration_id: string;
        settings: Record<string, unknown>;
        value: Array<{
            id: string;
            content: string;
            media_ids: string[];
            order: number;
            parent_post_id: string | null;
            state: string;
            release_url: string | null;
            error: string | null;
            published_at: string | null;
        }>;
    }>;
    created_at: string;
    updated_at: string;
}
