import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import MediaUploader from '../MediaUploader.vue';
import { useMediaStore } from '../../../stores/media';

const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { post: (...args: unknown[]) => post(...args) },
}));

beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
});

function fakeFile(name: string, type: string, size = 100): File {
    return new File([new Uint8Array(size)], name, { type });
}

function setInputFiles(input: HTMLInputElement, files: File[]): void {
    Object.defineProperty(input, 'files', { value: files, configurable: true });
}

it('uploading a valid file through the file input adds it to the store and emits uploaded', async () => {
    post.mockResolvedValueOnce({
        data: { data: { id: 'med-a', url: 'u', thumbnail_url: null, mime: 'image/jpeg', size_bytes: 1, width: 1, height: 1, original_filename: 'a.jpg', created_at: '' } },
    });

    const w = mount(MediaUploader);
    const input = w.find('input[type="file"]').element as HTMLInputElement;
    setInputFiles(input, [fakeFile('a.jpg', 'image/jpeg')]);
    await w.find('input[type="file"]').trigger('change');
    await flushPromises();

    const store = useMediaStore();
    expect(store.items).toHaveLength(1);
    expect(store.items[0].id).toBe('med-a');
    expect(w.emitted('uploaded')).toBeTruthy();
});

it('a disallowed file type is rejected client-side without ever calling the API', async () => {
    const w = mount(MediaUploader);
    const input = w.find('input[type="file"]').element as HTMLInputElement;
    setInputFiles(input, [fakeFile('malware.exe', 'application/x-msdownload')]);
    await w.find('input[type="file"]').trigger('change');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(w.text()).toMatch(/malware\.exe/);

    const store = useMediaStore();
    expect(store.items).toHaveLength(0);
});

it('a failed upload surfaces an error and leaves no item in the store (no phantom tile)', async () => {
    post.mockRejectedValueOnce(new Error('500 storage failure'));

    const w = mount(MediaUploader);
    const input = w.find('input[type="file"]').element as HTMLInputElement;
    setInputFiles(input, [fakeFile('video.mp4', 'video/mp4')]);
    await w.find('input[type="file"]').trigger('change');
    await flushPromises();

    const store = useMediaStore();
    expect(store.items).toHaveLength(0);
    expect(w.text()).toMatch(/video\.mp4/);
});

// Task 36 fix round 1, Minor 5: a 402 already shows the global warning
// toast (api/client.ts's response interceptor, color.md --warning) — a
// second, local --danger error line for the SAME event puts red and
// yellow feedback on screen at once for one failure, which color.md §3.8
// forbids. Every other failure (a genuine 500, a validation error) keeps
// its local line exactly as before; only 402 is suppressed here, because
// only 402 has a global toast already covering it.
it('a 402 does not add a local error line, since the global limit toast already covers it', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 402, data: { message: 'limit reached' } } });

    const w = mount(MediaUploader);
    const input = w.find('input[type="file"]').element as HTMLInputElement;
    setInputFiles(input, [fakeFile('video.mp4', 'video/mp4')]);
    await w.find('input[type="file"]').trigger('change');
    await flushPromises();

    const store = useMediaStore();
    expect(store.items).toHaveLength(0);
    expect(w.text()).not.toMatch(/video\.mp4/);
});

it('one bad file in a batch does not block or hide the outcome of the others', async () => {
    post.mockResolvedValueOnce({
        data: { data: { id: 'med-b', url: 'u', thumbnail_url: null, mime: 'image/png', size_bytes: 1, width: 1, height: 1, original_filename: 'good.png', created_at: '' } },
    });

    const w = mount(MediaUploader);
    const input = w.find('input[type="file"]').element as HTMLInputElement;
    setInputFiles(input, [fakeFile('bad.exe', 'application/x-msdownload'), fakeFile('good.png', 'image/png')]);
    await w.find('input[type="file"]').trigger('change');
    await flushPromises();

    const store = useMediaStore();
    expect(store.items.map((i) => i.id)).toEqual(['med-b']);
    expect(w.text()).toMatch(/bad\.exe/);
});
