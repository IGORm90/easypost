<?php

use App\Logging\RedactSensitiveContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        // LOG_PLAN.md Phase 1.1. JSON to stdout/stderr — the shape any
        // log shipper reading the container's stdout needs; the
        // default text formatter has nothing to extract fields from.
        // RedactSensitiveContext runs on every record as defence in
        // depth (see that class's docblock); it is not the primary
        // control.
        'app' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stdout'],
            'formatter' => JsonFormatter::class,
            'level' => env('LOG_LEVEL', 'info'),
            'processors' => [PsrLogMessageProcessor::class, RedactSensitiveContext::class],
        ],

        // Errors and above only. Level is hardcoded, not env-driven —
        // this channel must never go silent because LOG_LEVEL was
        // tightened for the 'app' channel.
        'errors' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => JsonFormatter::class,
            'level' => 'error',
            'processors' => [PsrLogMessageProcessor::class, RedactSensitiveContext::class],
        ],

        // Queue lifecycle + publish-outcome events (LOG_PLAN.md
        // requirement 4 and Phase 1.4/1.5).
        'jobs' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stdout'],
            'formatter' => JsonFormatter::class,
            'level' => 'info',
            'processors' => [PsrLogMessageProcessor::class, RedactSensitiveContext::class],
        ],

        // Feeds Log Viewer (/log-viewer), which reads dated plain-text files
        // in Laravel's line format and cannot parse the JSON the other
        // channels write to stdout. Add it to LOG_STACK in production
        // alongside `app,errors`; stdout stays the source for `docker logs`.
        //
        // `monolog` + RotatingFileHandler, not the `daily` driver: `daily`
        // silently ignores `processors`, so a daily channel carrying
        // RedactSensitiveContext would write channel tokens to disk in
        // clear with no error anywhere. Only the `monolog` driver applies
        // them. `formatter` is left unset on purpose so Laravel's default
        // LineFormatter (`[Y-m-d H:i:s] env.LEVEL: message {context}`) is
        // used — the format Log Viewer parses. The handler appends the date
        // itself, giving viewer-YYYY-MM-DD.log.
        // ViewerLogChannelTest and LogSecretsTest pin both properties.
        'viewer' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'with' => [
                'filename' => storage_path('logs/viewer.log'),
                'maxFiles' => (int) env('LOG_DAILY_DAYS', 14),
                // php-fpm (www-data) and horizon/scheduler (root) all append
                // to the same dated file, and the first writer creates it.
                // Without this a root-created 0644 file is unwritable for
                // www-data and every web-request log call throws.
                'filePermission' => 0666,
            ],
            'level' => env('LOG_LEVEL', 'info'),
            'processors' => [PsrLogMessageProcessor::class, RedactSensitiveContext::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
