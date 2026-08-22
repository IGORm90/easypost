import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import CommentThread from '../CommentThread.vue';
import en from '../../../i18n/en.json';
import type { Comment } from '../CommentThread.vue';

/**
 * The comments backend shipped with Task 22 — controller, service,
 * resource, request, migration and tests — and nothing in the SPA has
 * ever called it. These specs pin the shape of that contract:
 *
 *  - `GET /posts/{group}/comments` answers a bare array, oldest first,
 *    with no pagination and no `data` wrapper.
 *  - There is no PUT and no DELETE, so the thread is append-only: no
 *    edit, delete or reply affordance may appear.
 *  - Every comment carries the author's email, which the resource
 *    exposes on purpose for teammates — but the name is what identifies
 *    a person on screen, and putting the address there too would leak it
 *    to anyone glancing at the post.
 */
const get = vi.fn();
const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

function comment(overrides: Partial<Comment> = {}): Comment {
    return {
        id: 'c-1',
        content: 'Looks good, ship it.',
        user: { id: 'u-1', name: 'Dana Levin', email: 'dana@example.test' },
        created_at: '2026-08-22T10:00:00.000000Z',
        ...overrides,
    };
}

function respondWith(comments: Comment[]): void {
    get.mockResolvedValue({ data: comments });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    respondWith([comment()]);
});

async function mountThread(groupId = 'g-1') {
    const w = mount(CommentThread, { props: { groupId } });
    await flushPromises();

    return w;
}

it('loads the thread for the group it was given', async () => {
    const w = await mountThread('g-7');

    expect(get).toHaveBeenCalledWith('/posts/g-7/comments');
    expect(w.text()).toContain('Looks good, ship it.');
    expect(w.text()).toContain('Dana Levin');
});

it('shows a readable time rather than the raw timestamp the API sends', async () => {
    const w = await mountThread();

    expect(w.text()).not.toContain('2026-08-22T10:00:00.000000Z');
    expect(w.get('[data-test="comment-time"]').text()).toContain('2026');
});

it('identifies the author by name without putting their email on screen', async () => {
    const w = await mountThread();

    expect(w.text()).not.toContain('dana@example.test');
});

it('offers no edit, delete or reply control, because the API has no such endpoint', async () => {
    const w = await mountThread();

    expect(w.findAll('[data-test="comment-row"] button')).toHaveLength(0);
});

it('says what the thread is for instead of showing an empty box', async () => {
    respondWith([]);
    const w = await mountThread();

    expect(w.text()).toContain(en.comments.empty);
    expect(w.text()).toContain(en.comments.emptyHint);
});

it('appends the saved comment without re-reading the whole thread', async () => {
    post.mockResolvedValue({ data: comment({ id: 'c-2', content: 'One typo in the second line.' }) });
    const w = await mountThread();
    get.mockClear();

    await w.get('[data-test="comment-input"] textarea').setValue('One typo in the second line.');
    await w.get('form[data-test="comment-form"]').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/posts/g-1/comments', { content: 'One typo in the second line.' });
    expect(get).not.toHaveBeenCalled();
    expect(w.findAll('[data-test="comment-row"]')).toHaveLength(2);
    expect(w.text()).toContain('One typo in the second line.');
});

it('clears the box once the comment is in, so the next one starts empty', async () => {
    post.mockResolvedValue({ data: comment({ id: 'c-2', content: 'Shipped.' }) });
    const w = await mountThread();

    const box = w.get('[data-test="comment-input"] textarea');
    await box.setValue('Shipped.');
    await w.get('form[data-test="comment-form"]').trigger('submit');
    await flushPromises();

    expect((box.element as HTMLTextAreaElement).value).toBe('');
});

it('refuses to send an empty comment, rather than collecting a 422 for it', async () => {
    const w = await mountThread();

    expect((w.get('[data-test="comment-submit"]').element as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();
});

it('refuses to send a comment past the 5000-character cap', async () => {
    const w = await mountThread();

    await w.get('[data-test="comment-input"] textarea').setValue('x'.repeat(5001));

    expect((w.get('[data-test="comment-submit"]').element as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();
});

it('keeps the typed text when sending fails, so a flaky network costs nothing', async () => {
    post.mockRejectedValue(new Error('network'));
    const w = await mountThread();

    const box = w.get('[data-test="comment-input"] textarea');
    await box.setValue('Worth another look.');
    await w.get('form[data-test="comment-form"]').trigger('submit');
    await flushPromises();

    expect(w.text()).toContain(en.comments.sendError);
    expect((box.element as HTMLTextAreaElement).value).toBe('Worth another look.');
    // And nothing was added to the thread — the list only ever grows
    // once the server has confirmed the row.
    expect(w.findAll('[data-test="comment-row"]')).toHaveLength(1);
});

it('reports a failed load instead of an empty thread that reads as "no comments"', async () => {
    get.mockRejectedValue(new Error('network'));
    const w = await mountThread();

    expect(w.text()).toContain(en.comments.loadError);
    expect(w.text()).not.toContain(en.comments.empty);
});
