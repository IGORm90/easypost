<?php

use Laravel\Pulse\Recorders;

/*
 * Pulse's defaults are tuned for a different app: a 1000 ms threshold for
 * everything, no ignore list for the dashboards we add, and — the one that
 * matters — no collapsing of outgoing URLs. A bot token travels in the PATH
 * of some providers' API URLs, and Pulse stores and displays the URL of every
 * slow outgoing request, so without grouping it would persist channel
 * credentials into pulse_aggregates.
 *
 * These apply the config the way Pulse's own Groups/Ignores traits do
 * (first matching group wins; any matching ignore pattern skips).
 */
function pulseGroup(string $recorder, string $value): string
{
    foreach (config("pulse.recorders.{$recorder}.groups", []) as $pattern => $replacement) {
        $group = preg_replace($pattern, $replacement, $value, count: $count);

        if ($count > 0 && $group !== null) {
            return $group;
        }
    }

    return $value;
}

function pulseIgnores(string $recorder, string $value): bool
{
    foreach (config("pulse.recorders.{$recorder}.ignore", []) as $pattern) {
        if (preg_match($pattern, $value)) {
            return true;
        }
    }

    return false;
}

it('collapses outgoing request urls to the host so path-borne credentials are never stored', function () {
    $recorder = Recorders\SlowOutgoingRequests::class;

    expect(pulseGroup($recorder, 'https://api.example.test/bot123456:SECRET-TOKEN/sendMessage'))
        ->toBe('api.example.test')
        ->and(pulseGroup($recorder, 'https://api.example.test:8443/repos/1/messages/99?access_token=abc'))
        ->toBe('api.example.test:8443');
});

it('keeps the monitoring dashboards out of the request statistics', function (string $recorder) {
    foreach ([
        '/pulse',
        '/horizon/{view?}',
        '/horizon/api/stats',
        '/log-viewer/{view?}',
        '/log-viewer/api/files',
        '/livewire-5a1b2c3d/update',
        '/up',
    ] as $uri) {
        expect(pulseIgnores($recorder, $uri))->toBeTrue("{$recorder} should ignore {$uri}");
    }

    foreach (['/api/posts', '/api/integrations/{integration}', '/'] as $uri) {
        expect(pulseIgnores($recorder, $uri))->toBeFalse("{$recorder} must not ignore {$uri}");
    }
})->with([Recorders\SlowRequests::class, Recorders\UserRequests::class]);

it('uses the thresholds and sampling this product was sized for', function () {
    expect(config('pulse.recorders.'.Recorders\SlowRequests::class.'.threshold'))->toBe(500)
        ->and(config('pulse.recorders.'.Recorders\SlowQueries::class.'.threshold'))->toBe(300)
        ->and(config('pulse.recorders.'.Recorders\SlowJobs::class.'.threshold'))->toBe(5000)
        ->and(config('pulse.recorders.'.Recorders\SlowOutgoingRequests::class.'.threshold'))->toBe(2000)
        ->and(config('pulse.recorders.'.Recorders\UserRequests::class.'.sample_rate'))->toBe(0.2)
        ->and(config('pulse.recorders.'.Recorders\UserJobs::class.'.sample_rate'))->toBe(0.2)
        ->and(config('pulse.recorders.'.Recorders\CacheInteractions::class.'.sample_rate'))->toBe(0.1);
});

it('keeps seven days of metrics in the application database', function () {
    expect(config('pulse.storage.driver'))->toBe('database')
        ->and(config('pulse.storage.trim.keep'))->toBe('7 days')
        ->and(config('pulse.ingest.driver'))->toBe('storage');
});

/*
 * Pulse reads CPU load by shelling out to `top`, which the slim php image
 * does not ship. Without it `pulse:check` only prints "top: not found" to
 * the container log and records 0% CPU, so the Servers card looks healthy
 * while measuring nothing. No unit test can run `top` in CI, so the image
 * recipe itself is asserted.
 */
it('installs procps in the php image so the servers card can measure cpu', function () {
    $dockerfile = (string) file_get_contents(base_path('docker/php/Dockerfile'));

    expect($dockerfile)->toMatch('/^\s+procps\s*\\\\?$/m');
});
