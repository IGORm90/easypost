<?php

use Symfony\Component\Yaml\Yaml;

/*
 * DEPLOY.md, stage 1. Nothing in CI builds the production image or runs its
 * Compose file, so every property below is asserted from the files themselves
 * — the same approach, and for the same reason, as ComposeServicesTest does
 * for the dev stack. Each one is a failure that is silent until it happens on
 * the server: a published Postgres port, a literal password, a 413 from a
 * proxy before validation runs, a worker OOM-killed in the middle of a
 * publish, a dev dependency shipped to production.
 */

const PRODUCTION_LONG_RUNNING = ['caddy', 'nginx', 'php', 'horizon', 'scheduler', 'pulse', 'postgres', 'redis', 'minio'];
const PRODUCTION_APP_SERVICES = ['php', 'horizon', 'scheduler', 'pulse'];

function productionRoot(): string
{
    // __DIR__, not base_path(): see ComposeServicesTest.
    return dirname(__DIR__, 3);
}

function productionFile(string $relative): string
{
    $path = productionRoot().'/'.$relative;

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/** @return array<string, mixed> */
function productionCompose(): array
{
    $path = productionRoot().'/docker/compose.production.yaml';

    expect($path)->toBeFile();

    /** @var array<string, mixed> $compose */
    $compose = Yaml::parseFile($path);

    return $compose;
}

/** @return array<string, array<string, mixed>> */
function productionServices(): array
{
    /** @var array<string, array<string, mixed>> $services */
    $services = productionCompose()['services'];

    return $services;
}

/**
 * The file with its comment lines removed. These files explain, in comments,
 * why they do NOT contain certain things (`event:cache`, `set_real_ip_from`,
 * a `${VAR:?...}` example); a "must not contain" check has to look at the
 * configuration, not at the explanation of its absence.
 */
function withoutComments(string $text): string
{
    $lines = array_filter(
        explode("\n", $text),
        fn (string $line) => ! preg_match('/^\s*[#;]/', $line),
    );

    return implode("\n", $lines);
}

function productionMegabytes(string $limit): int
{
    return match (true) {
        str_ends_with($limit, 'g') => (int) $limit * 1024,
        str_ends_with($limit, 'm') => (int) $limit,
        default => intdiv((int) $limit, 1024 * 1024),
    };
}

/** Text of one Dockerfile stage, from its FROM line to the next FROM. */
function dockerfileStage(string $name): string
{
    $dockerfile = productionFile('docker/production/Dockerfile');
    $stages = preg_split('/^(?=FROM\s)/m', $dockerfile, flags: PREG_SPLIT_NO_EMPTY) ?: [];

    foreach ($stages as $stage) {
        if (preg_match('/^FROM\s+\S+\s+AS\s+'.preg_quote($name, '/').'\s*$/mi', $stage) === 1) {
            return $stage;
        }
    }

    throw new RuntimeException("Dockerfile has no stage named [{$name}].");
}

/** @return list<string> */
function requiredComposeVariables(): array
{
    $text = withoutComments(productionFile('docker/compose.production.yaml'));
    preg_match_all('/\$\{([A-Z0-9_]+):\?/', $text, $matches);

    return array_values(array_unique($matches[1]));
}

// ---------------------------------------------------------------- Compose

it('runs exactly the production services: no Vite dev server, no Mailpit', function () {
    $names = array_keys(productionServices());

    expect($names)->toEqualCanonicalizing([...PRODUCTION_LONG_RUNNING, 'minio-init'])
        ->and($names)->not->toContain('node')
        ->and($names)->not->toContain('mailpit');
});

it('names the project, so every release lands on the same volumes', function () {
    expect(productionCompose()['name'] ?? null)->toBe('easypost');
});

it('publishes only 80 and 443, and only from Caddy', function () {
    $published = [];

    foreach (productionServices() as $name => $service) {
        foreach ($service['ports'] ?? [] as $port) {
            $published[$name][] = (string) $port;
        }
    }

    expect(array_keys($published))->toBe(['caddy'])
        ->and($published['caddy'])->toEqualCanonicalizing(['80:80', '443:443']);
});

it('bind-mounts no application source, only the config files it names', function () {
    foreach (productionServices() as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            $source = explode(':', (string) $volume)[0];

            expect($source)->not->toStartWith('..', "{$name} mounts the repository ({$volume}); production runs the image");
        }
    }
});

it('builds nothing on the server: every service runs a pinned image', function () {
    foreach (productionServices() as $name => $service) {
        expect($service)->not->toHaveKey('build');

        $image = (string) ($service['image'] ?? '');
        expect($image)->not->toBe('', "{$name} has no image")
            ->and($image)->not->toEndWith(':latest');
    }

    foreach ([...PRODUCTION_APP_SERVICES, 'nginx'] as $name) {
        expect(productionServices()[$name]['image'])->toContain('${EASYPOST_TAG:?');
    }
});

it('runs the app image for php, horizon, scheduler and pulse, and the web image for nginx', function () {
    foreach (PRODUCTION_APP_SERVICES as $name) {
        expect(productionServices()[$name]['image'])->toStartWith('${EASYPOST_IMAGE:?');
    }

    expect(productionServices()['nginx']['image'])->toStartWith('${EASYPOST_WEB_IMAGE:?');
});

it('gives every long-running service a healthcheck, a memory cap and a restart policy', function (string $name) {
    $service = productionServices()[$name];

    expect($service['healthcheck']['test'] ?? null)->not->toBeNull("{$name} has no healthcheck")
        ->and($service['mem_limit'] ?? null)->not->toBeNull("{$name} has no mem_limit")
        ->and($service['restart'] ?? null)->toBe('unless-stopped');
})->with(PRODUCTION_LONG_RUNNING);

it('rotates every service log, so stdout cannot fill the disk', function (string $name) {
    $logging = productionServices()[$name]['logging'] ?? [];

    expect($logging['driver'] ?? null)->toBe('json-file')
        ->and($logging['options']['max-size'] ?? null)->toBe('10m')
        ->and($logging['options']['max-file'] ?? null)->toBe('3');
})->with([...PRODUCTION_LONG_RUNNING, 'minio-init']);

it('keeps the memory caps inside the 4 GB host budget', function () {
    $total = array_sum(array_map(
        fn (string $name) => productionMegabytes((string) productionServices()[$name]['mem_limit']),
        PRODUCTION_LONG_RUNNING,
    ));

    // DEPLOY.md, "Бюджет памяти": ~2.6 GB of caps leaves ~1.4 GB for the OS,
    // Docker and the page cache. Past 2.7 GB the host starts swapping.
    expect($total)->toBeLessThanOrEqual(2700);
});

it('sizes horizon for its workers and gives a running publish time to finish on stop', function () {
    $horizon = productionServices()['horizon'];

    $supervisors = config('horizon.environments.*');
    $workers = array_sum(array_map(fn (array $s) => $s['maxProcesses'], $supervisors));
    $slowest = max(array_map(fn (array $s) => $s['timeout'], $supervisors));

    expect(productionMegabytes((string) $horizon['mem_limit']))->toBeGreaterThan($workers * (int) config('horizon.memory_limit'))
        ->and((int) rtrim((string) $horizon['stop_grace_period'], 's'))->toBeGreaterThan($slowest)
        ->and((string) $horizon['command'])->toBe('php artisan horizon');
});

it('takes the app configuration from the server-side .env, not from the image', function (string $name) {
    expect(productionServices()[$name]['env_file'] ?? null)->toBe('../.env');
})->with(PRODUCTION_APP_SERVICES);

it('starts the app services only once postgres and redis answer, and the bucket exists', function (string $name) {
    $depends = productionServices()[$name]['depends_on'] ?? [];

    expect($depends['postgres']['condition'] ?? null)->toBe('service_healthy')
        ->and($depends['redis']['condition'] ?? null)->toBe('service_healthy');

    if ($name !== 'pulse') {
        expect($depends['minio-init']['condition'] ?? null)->toBe('service_completed_successfully');
    }
})->with(PRODUCTION_APP_SERVICES);

it('has no literal passwords: the database and MinIO secrets must come from the environment', function () {
    $postgres = productionServices()['postgres']['environment'];
    $minio = productionServices()['minio']['environment'];

    expect((string) $postgres['POSTGRES_PASSWORD'])->toStartWith('${DB_PASSWORD:?')
        ->and((string) $postgres['POSTGRES_USER'])->toStartWith('${DB_USERNAME:?')
        ->and((string) $postgres['POSTGRES_DB'])->toStartWith('${DB_DATABASE:?')
        ->and((string) $minio['MINIO_ROOT_PASSWORD'])->toStartWith('${MINIO_ROOT_PASSWORD:?')
        ->and((string) $minio['MINIO_ROOT_USER'])->toStartWith('${MINIO_ROOT_USER:?');

    $text = withoutComments(productionFile('docker/compose.production.yaml'));
    expect($text)->not->toContain('secret')
        ->and($text)->not->toContain('minio123');
});

it('runs redis so queued jobs are never evicted and survive a recreate', function () {
    $redis = productionServices()['redis'];
    $command = implode(' ', (array) $redis['command']);

    expect($command)->toContain('--maxmemory-policy noeviction')
        ->and($command)->toContain('--appendonly yes')
        ->and(implode(' ', $redis['volumes']))->toContain(':/data');
});

it('keeps postgres, redis and the MinIO console off the host network', function (string $name) {
    expect(productionServices()[$name])->not->toHaveKey('ports');
})->with(['postgres', 'redis', 'minio', 'php', 'nginx']);

it('gives Caddy a fixed address, so TRUSTED_PROXIES can name exactly it', function () {
    $compose = productionCompose();
    $subnet = $compose['networks']['default']['ipam']['config'][0]['subnet'] ?? null;
    $address = productionServices()['caddy']['networks']['default']['ipv4_address'] ?? null;

    expect($subnet)->not->toBeNull()
        ->and($address)->not->toBeNull();

    [$network, $bits] = explode('/', (string) $subnet);
    $mask = ~((1 << (32 - (int) $bits)) - 1);

    expect(ip2long((string) $address) & $mask)->toBe(ip2long($network) & $mask);
});

it('declares a named volume for everything that must survive a recreate', function () {
    expect(array_keys(productionCompose()['volumes']))->toEqualCanonicalizing([
        'pgdata', 'redisdata', 'minio', 'easypost-logs', 'caddy-data', 'caddy-config',
    ]);
});

// ------------------------------------------------------------ Environment

it('ships an environment template that supplies every variable Compose requires', function () {
    $template = productionFile('.env.production.example');

    foreach (requiredComposeVariables() as $variable) {
        if ($variable === 'EASYPOST_TAG') {
            continue; // exported by scripts/lib.sh from .current-tag, never written into .env
        }

        expect($template)->toMatch('/^'.preg_quote($variable, '/').'=/m');
    }
});

it('launches closed, with debug off and no secret filled in', function () {
    $template = productionFile('.env.production.example');

    expect($template)->toMatch('/^APP_ENV=production$/m')
        ->and($template)->toMatch('/^APP_DEBUG=false$/m')
        ->and($template)->toMatch('/^REGISTRATION_ENABLED=false$/m')
        ->and($template)->toMatch('/^SESSION_SECURE_COOKIE=true$/m')
        ->and($template)->toMatch('/^REDIS_QUEUE_RETRY_AFTER=300$/m')
        ->and($template)->toMatch('/^APP_KEY=$/m')
        ->and($template)->toMatch('/^DB_PASSWORD=$/m')
        ->and($template)->toMatch('/^MINIO_ROOT_PASSWORD=$/m');
});

it('trusts exactly the address Caddy is given', function () {
    $address = productionServices()['caddy']['networks']['default']['ipv4_address'];

    expect(productionFile('.env.production.example'))->toMatch('/^TRUSTED_PROXIES='.preg_quote((string) $address, '/').'$/m');
});

// ------------------------------------------------------------- Dockerfile

it('builds the app in stages: vendor, assets, then a runtime with neither Composer nor Node', function () {
    $vendor = dockerfileStage('vendor');
    $assets = dockerfileStage('assets');
    $app = dockerfileStage('app');

    expect($vendor)->toContain('composer install')
        ->and($vendor)->toContain('--no-dev')
        ->and($vendor)->toContain('--prefer-dist')
        ->and($vendor)->toContain('--no-interaction')
        ->and($assets)->toContain('npm ci')
        ->and($assets)->toContain('npm run build')
        ->and($app)->not->toContain('npm ')
        ->and($app)->not->toContain('apt-get install -y --no-install-recommends git')
        ->and($app)->toContain('COPY --from=vendor')
        ->and($app)->toContain('COPY --from=assets');
});

it('checks the platform requirements inside the final image, not only where they were resolved', function () {
    expect(dockerfileStage('app'))->toContain('composer check-platform-reqs --no-dev');
});

it('never bakes a secret or a build-time frontend variable into a layer', function () {
    $dockerfile = productionFile('docker/production/Dockerfile');

    expect($dockerfile)->not->toMatch('/^\s*COPY\s+.*\.env\b/mi')
        ->and($dockerfile)->not->toMatch('/^\s*(ARG|ENV)\s+\S*(SECRET|PASSWORD|TOKEN|KEY)\S*/mi')
        ->and($dockerfile)->not->toMatch('/^\s*(ARG|ENV)\s+VITE_/mi');
});

it('routes every container start through the entrypoint that builds the caches', function () {
    $dockerfile = dockerfileStage('app');

    expect($dockerfile)->toMatch('/^ENTRYPOINT\s+\["\/usr\/local\/bin\/entrypoint\.sh"\]/m')
        ->and($dockerfile)->toMatch('/^CMD\s+\["php-fpm"\]/m');
});

it('caches config, routes and views at start with that container\'s own environment', function () {
    $entrypoint = withoutComments(productionFile('docker/production/entrypoint.sh'));

    expect(productionFile('docker/production/entrypoint.sh'))->toStartWith('#!/bin/sh')
        ->and($entrypoint)->toContain('set -e')
        ->and($entrypoint)->toContain('php artisan config:cache')
        ->and($entrypoint)->toContain('php artisan route:cache')
        ->and($entrypoint)->toContain('php artisan view:cache')
        // withEvents(discover: false) in bootstrap/app.php: caching events would
        // resurrect the discovery that was switched off on purpose.
        ->and($entrypoint)->not->toContain('event:cache')
        // The stock image entrypoint turns `-flags` into php-fpm arguments.
        ->and($entrypoint)->toContain('exec docker-php-entrypoint "$@"');
});

it('puts the same public tree into the web image as the app image was built with', function () {
    $web = dockerfileStage('web');

    expect($web)->toContain('FROM nginx:')
        ->and($web)->toContain('COPY --from=app /var/www/html/public /var/www/html/public')
        ->and($web)->toContain('production.conf');
});

it('keeps secrets, VCS history, dev tooling and the Vite hot file out of the build context', function () {
    $ignored = array_map('trim', explode("\n", productionFile('.dockerignore')));

    foreach (['.env', '.env.*', '.git', 'node_modules', 'vendor', 'public/hot', 'public/build', 'storage/logs', 'tests'] as $entry) {
        expect($ignored)->toContain($entry);
    }
});

it('hardens and warms php for production', function () {
    $ini = productionFile('docker/production/php.ini');

    expect($ini)->toMatch('/^opcache\.enable\s*=\s*1$/m')
        ->and($ini)->toMatch('/^opcache\.validate_timestamps\s*=\s*0$/m')
        ->and($ini)->toMatch('/^expose_php\s*=\s*Off$/m')
        ->and($ini)->toMatch('/^display_errors\s*=\s*Off$/m')
        // The upload ceilings are shared with the dev ini and pinned by the media tests.
        ->and(productionFile('docker/php/php.ini'))->toContain('post_max_size = 108M');
});

// ------------------------------------------------------------ nginx, Caddy

it('keeps nginx accepting the whole 100 MB multipart body, and reading the client address from php, not itself', function () {
    $conf = withoutComments(productionFile('docker/nginx/production.conf'));

    expect($conf)->toContain('client_max_body_size 108M')
        ->and($conf)->toContain('fastcgi_pass php:9000')
        ->and($conf)->toContain('fastcgi_param HTTP_X_REQUEST_ID')
        // real_ip would make php see the client as REMOTE_ADDR, so Laravel would
        // no longer recognise Caddy as a trusted proxy and would drop the
        // forwarded https scheme. The client address stays Laravel's job.
        ->and($conf)->not->toContain('set_real_ip_from');
});

it('lets Caddy pass the same 108 MB body nginx and php accept, so validation answers first', function () {
    $caddy = productionFile('docker/production/Caddyfile');

    preg_match('/max_size\s+(\d+)\s*MB/i', $caddy, $matches);

    expect((int) ($matches[1] ?? 0))->toBeGreaterThanOrEqual(108);
});

it('proxies the app host to nginx and the media host to MinIO objects only', function () {
    $caddy = productionFile('docker/production/Caddyfile');

    expect($caddy)->toContain('{$APP_HOST}')
        ->and($caddy)->toContain('{$MEDIA_HOST}')
        ->and($caddy)->toContain('reverse_proxy nginx:80')
        ->and($caddy)->toContain('reverse_proxy minio:9000')
        ->and($caddy)->toMatch('/method\s+GET\s+HEAD/')
        ->and($caddy)->toContain('/media/')
        ->and($caddy)->not->toContain('minio:9001');
});
