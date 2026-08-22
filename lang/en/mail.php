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

    'recurrence_paused' => [
        'subject' => 'A repeating post has stopped repeating',
        'greeting' => 'Hello :name!',
        'line' => 'Your repeating post published on :date, but we could not schedule the next one.',
        'unknown_date' => 'an unknown date',
        'reason' => 'Your organization has used up its posts for this month, and every occurrence of a repeating post counts as one.',
        'action_line' => 'The series has stopped. Open the post and schedule it again once you have room, or ask an administrator about your plan.',
    ],

    'weekly_digest' => [
        'subject' => 'Your weekly digest',
        'greeting' => 'Hello :name!',
        'intro' => 'Here is what happened across your organizations in the last week, and what is coming up in the next one.',
        'published_heading' => 'Published this week',
        'published_empty' => 'Nothing was published this week.',
        'failed_heading' => 'Failed this week',
        'failed_empty' => 'Nothing failed this week.',
        'scheduled_heading' => 'Scheduled for the coming week',
        'scheduled_empty' => 'Nothing is scheduled for the coming week.',
        'more' => 'and :count more',
        'footer' => 'You are receiving this email because weekly digests are enabled in your notification settings.',
    ],

];
