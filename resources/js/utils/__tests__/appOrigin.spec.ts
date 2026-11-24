import { afterEach, describe, expect, it } from 'vitest';
import { appApiBase, appUrl } from '../appOrigin';

function setMeta(content: string | null): void {
    document.head.querySelector('meta[name="app-url"]')?.remove();
    if (content !== null) {
        const meta = document.createElement('meta');
        meta.name = 'app-url';
        meta.content = content;
        document.head.appendChild(meta);
    }
}

describe('appOrigin', () => {
    afterEach(() => setMeta(null));

    it('stays relative without the meta tag', () => {
        expect(appApiBase()).toBe('/api');
        expect(appUrl()).toBe('');
    });

    it('stays relative when the app host is this origin', () => {
        setMeta(window.location.origin);
        expect(appApiBase()).toBe('/api');
        expect(appUrl()).toBe('');
    });

    it('goes absolute when the app lives on another origin', () => {
        setMeta('https://app.example.com');
        expect(appApiBase()).toBe('https://app.example.com/api');
        expect(appUrl()).toBe('https://app.example.com');
    });

    it('ignores a malformed value', () => {
        setMeta('not a url');
        expect(appApiBase()).toBe('/api');
    });
});
