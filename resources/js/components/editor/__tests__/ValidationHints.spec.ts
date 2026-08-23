import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ValidationHints from '../ValidationHints.vue';
import type { ProviderCapabilities } from '../types';

/**
 * Block 5.0.5: a network that publishes nothing without an attachment
 * (Instagram) is a fact the customer needs BEFORE writing a caption, not
 * a 422 after pressing schedule. The backend refuses such a post with
 * `media_required`; this hint is the half of that rule the customer can
 * actually act on.
 */
function capabilities(overrides: Partial<ProviderCapabilities> = {}): ProviderCapabilities {
    return {
        maxCharacters: 2200,
        maxImages: 10,
        maxVideos: 1,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 8_000_000,
        allowsMixedMedia: false,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: false,
        requiresAltText: false,
        ...overrides,
    };
}

describe('media requirement', () => {
    it('tells the customer when the network refuses a post without media', () => {
        const w = mount(ValidationHints, {
            props: { capabilities: capabilities({ requiresMedia: true }), threadLength: 1 },
        });

        expect(w.find('[data-testid="hint-media-required"]').exists()).toBe(true);
        // Not colour alone (color.md §4) and not an empty i18n miss.
        expect(w.find('[data-testid="hint-media-required"]').text().trim().length).toBeGreaterThan(0);
    });

    it('says nothing about media requirements on a network that has none', () => {
        const w = mount(ValidationHints, {
            props: { capabilities: capabilities(), threadLength: 1 },
        });

        expect(w.find('[data-testid="hint-media-required"]').exists()).toBe(false);
    });
});
