<?php

use App\Models\Media;
use App\Services\Media\Exceptions\MediaTemporaryFileException;
use App\Services\Media\MediaTempFiles;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery\Expectation;

/*
 * Re-review round 2: the PARTIAL-WRITE case — a read that fails part-way
 * through, after the temp file exists and has bytes in it — was argued
 * structurally but never executed. It is the worst of the leak cases,
 * because what it leaves behind is not a 0-byte placeholder but a
 * half-written copy of a customer's file.
 *
 * Driven with a real PHP stream wrapper that hands out bytes and then
 * fails, not with a mocked "read failure": fread() returning false
 * mid-loop is the actual condition MediaTempFiles::copy() branches on.
 */
final class HalfFailingStream
{
    /** @var resource|null */
    public $context;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $this->reads++;

        // One good chunk (so the temp file is genuinely non-empty), then
        // a hard failure.
        return $this->reads === 1 ? str_repeat('A', 4096) : false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void {}
}

beforeEach(function () {
    if (in_array('halffail', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('halffail');
    }

    stream_wrapper_register('halffail', HalfFailingStream::class);
});

afterEach(function () {
    if (in_array('halffail', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('halffail');
    }
});

it('leaves nothing on disk when the read fails part-way through', function () {
    $media = new Media(['disk' => 'media', 'path' => 'org/1/video.mp4']);
    $media->id = 'media-id';

    $disk = Mockery::mock(Filesystem::class);

    /** @var Expectation $readStream */
    $readStream = $disk->shouldReceive('readStream');
    $readStream->with('org/1/video.mp4')->andReturn(fopen('halffail://x', 'rb'));

    // Storage::set(), not Storage::shouldReceive('disk')->with(...):
    // the facade's own mock helper returns a union type PHPStan cannot
    // narrow, and swapping the resolved disk is what this test actually
    // needs — a `media` disk whose readStream() fails half way.
    Storage::set('media', $disk);

    $before = glob(sys_get_temp_dir().'/publish-media-*') ?: [];

    expect(fn () => (new MediaTempFiles)->copy($media))
        ->toThrow(MediaTemporaryFileException::class);

    $after = glob(sys_get_temp_dir().'/publish-media-*') ?: [];

    // Not merely "the same count": the half-written file would be a NEW
    // path, so comparing the sets is what actually proves nothing was
    // left behind.
    expect(array_diff($after, $before))->toBe([]);
});
