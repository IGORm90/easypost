<?php

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/*
 * Production mail goes through Resend (MAIL_MAILER=resend, DEPLOY.md).
 * config/mail.php already declares the `resend` mailer and services.php reads
 * RESEND_API_KEY, but Laravel builds that transport with resend/resend-php,
 * which is not installed by default: without the package the first email of a
 * production deploy (the verification mail at sign-up) dies with "class not
 * found" after everything else has passed. CI never sends mail, so only this
 * test notices.
 */
it('builds the resend mailer, which needs the resend/resend-php package', function () {
    config(['services.resend.key' => 're_test_key']);

    expect(Mail::mailer('resend'))->toBeInstanceOf(Mailer::class);
});
