<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor: redacts any context/extra value whose key names a
 * secret, at any nesting depth. Defence in depth only — the primary
 * control is not logging the value at all (see call sites and
 * Integration's docblock, which explains why $hidden alone does not
 * protect against a raw `Log::info($integration)`).
 *
 * Must implement ProcessorInterface, not just __invoke(LogRecord): array —
 * LogManager::createMonologDriver() checks `is_a($processor,
 * ProcessorInterface::class, true)` on the class name BEFORE
 * instantiating it, and throws InvalidArgumentException if it doesn't.
 * That failure is caught by LogManager::get()'s catch(Throwable) and
 * silently downgraded to the emergency logger writing to
 * storage/logs/laravel.log — every configured channel appears to work
 * but writes to the wrong place with no error surfaced anywhere.
 */
class RedactSensitiveContext implements ProcessorInterface
{
    private const PATTERN = '/token|secret|password|authorization|cookie|api[_-]?key/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $result[$key] = '[redacted]';

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }
}
