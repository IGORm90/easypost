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

];
