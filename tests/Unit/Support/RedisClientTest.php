<?php

use Illuminate\Support\Env;
use Predis\Client;

/*
 * The only Redis client this project ships is predis (composer.json), and the
 * php image has no phpredis extension. Laravel's own default is `phpredis`, so
 * a deployment whose .env forgot REDIS_CLIENT=predis does not degrade: every
 * request that touches the session, the cache or the queue dies with
 * `Class "Redis" not found`. The first production-image rehearsal hit exactly
 * that, with an env template that lacked the line. The default must be the
 * client that exists.
 */
it('defaults to predis, the only Redis client installed, when REDIS_CLIENT is unset', function () {
    $repository = Env::getRepository();
    $repository->clear('REDIS_CLIENT');

    $config = require config_path('database.php');

    expect($config['redis']['client'])->toBe('predis');
});

it('does not depend on the phpredis extension being present', function () {
    expect(extension_loaded('redis'))->toBeFalse()
        ->and(class_exists(Client::class))->toBeTrue();
})->skip(fn () => extension_loaded('redis'), 'phpredis is installed on this machine');
