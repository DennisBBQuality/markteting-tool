<?php

return [
    // Opt-in only, after the dedicated sender and transport have been verified.
    // Never inherit MAIL_FROM_ADDRESS (which may be a personal mailbox).
    'email_enabled' => env('PITBOARD_NOTIFICATION_EMAIL_ENABLED', false),
    'from_address' => env('PITBOARD_NOTIFICATION_FROM_ADDRESS'),
    'mailer' => env('PITBOARD_NOTIFICATION_MAILER', 'smtp'),
    'url' => env('APP_URL'),
];
