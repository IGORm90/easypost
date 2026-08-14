<?php

/**
 * Fixture for tests/Feature/Media/ImportFromUrlSsrfTest.php: a real HTTP
 * server (run via `php -S` on 127.0.0.1) standing in for an internal
 * service that must never be reachable through
 * `POST /api/media/from-url`.
 *
 * The body is a marker string, so the test can assert on what was (or,
 * after the fix, was not) retrieved rather than on an exception type
 * alone: the reviewer's proof of this defect was the loopback body
 * coming back through the real download path, and that is exactly what
 * the test re-runs.
 */
header('Content-Type: application/octet-stream');
echo 'INTERNAL-LOOPBACK-SECRET';
