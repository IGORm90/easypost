<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\Media\Support\SizeCappedSinkStream::write() the
 * moment cumulative bytes written to it exceed its configured cap.
 * Deliberately a distinct type from MediaImportException: it is thrown
 * from INSIDE Guzzle's CURLOPT_WRITEFUNCTION callback, mid-transfer, not
 * by MediaUploadService's own code — MediaUploadService::download()
 * catches it specifically (rather than only inspecting bytes-on-disk
 * after the fact) to report `MediaImportException::forSizeExceeded()`
 * with certainty, not a guess based on how much happened to land on
 * disk before the abort.
 */
class SinkSizeLimitExceededException extends RuntimeException {}
