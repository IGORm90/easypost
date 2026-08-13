/**
 * One segment of a channel's thread, as far as a preview needs to know —
 * intentionally a subset of `PostSegment` (editor/types.ts): previews
 * never touch `media_ids` (Tasks 27-29 build media; until then it is
 * always `[]`, and no preview here renders a media placeholder off of
 * it — see registry.ts's docblock for why).
 */
export interface PreviewSegment {
    content: string;
}

/**
 * The uniform prop contract every entry in the registry (including
 * GenericPreview, the fallback) accepts. `PreviewPane.vue` binds these
 * three props to whatever `resolvePreview()` returns without knowing —
 * or needing to know — which provider it resolved to.
 */
export interface PreviewProps {
    channelName: string;
    /** The integration's own picture (Task 13's OAuth profile picture), never post content. */
    channelPicture: string | null;
    segments: PreviewSegment[];
}
