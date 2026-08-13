<?php

return [

    'verify_email' => [
        'subject' => 'Verify your email address',
        'greeting' => 'Hello :name!',
        'line' => 'Please click the button below to verify your email address.',
        'action' => 'Verify Email Address',
        'expire' => 'This verification link will expire in :count minutes.',
        'trouble' => 'If you did not create an account, no further action is required.',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'greeting' => 'Hello :name!',
        'line' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset Password',
        'expire' => 'This password reset link will expire in :count minutes.',
        'trouble' => 'If you did not request a password reset, no further action is required.',
    ],

    'integration_broken' => [
        'subject' => 'A channel needs to be reconnected',
        'greeting' => 'Hello :name!',
        'line' => 'We could not refresh the connection for ":channel". The account it was connected with has revoked or expired its access.',
        'action_line' => 'Posts scheduled on this channel will not go out until it is reconnected.',
        'trouble' => 'Please reconnect this channel from your organization settings.',
    ],

    'post_failed' => [
        'subject' => 'A scheduled post failed to publish',
        'greeting' => 'Hello :name!',
        'line' => 'Your post scheduled for :date could not be published.',
        'unknown_date' => 'an unknown date',
        'action_line' => 'Please review and retry it from your calendar.',
    ],

];
