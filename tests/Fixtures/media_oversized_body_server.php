<?php

/**
 * Fixture for tests/Unit/Services/Media/SizeCappedSinkStreamTest.php:
 * a real HTTP server (run via `php -S`), not a mock, so the covering
 * test for review finding 5 actually exercises real cURL delivering
 * real response chunks through Guzzle's CURLOPT_WRITEFUNCTION -- the
 * exact mechanism SizeCappedSinkStream depends on and Http::fake()
 * (used by every other test in this suite) never reaches.
 *
 * Serves a body far larger than any cap the test configures, so the
 * transfer would only complete in full if SizeCappedSinkStream failed
 * to abort it early.
 */
header('Content-Type: application/octet-stream');
echo str_repeat('a', 5 * 1024 * 1024);
