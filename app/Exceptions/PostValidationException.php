<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller needs `PostValidationService::validate()`'s
 * result to fail the request/job outright instead of being handled as
 * a plain return value. `validate()` itself never throws — it always
 * returns the (possibly empty) list of errors, exactly the shape
 * described on `PostValidationService`, so both call sites (Task 20's
 * scheduling endpoint and Task 30's publish job) can inspect every
 * violation before deciding what to do. This exception exists for the
 * callers that just want to abort as soon as validation failed: build
 * it from that same errors array with `::forErrors()` so the caught
 * exception still carries the full, renderable list rather than a
 * single flattened message.
 *
 * @phpstan-type ValidationError array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}
 */
class PostValidationException extends RuntimeException
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(self::summarize($errors));
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    public static function forErrors(array $errors): self
    {
        return new self($errors);
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private static function summarize(array $errors): string
    {
        if ($errors === []) {
            return 'Post content failed validation.';
        }

        $codes = implode(', ', array_unique(array_column($errors, 'code')));

        return "Post content failed validation: {$codes}.";
    }
}
