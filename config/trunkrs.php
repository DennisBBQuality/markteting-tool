<?php

return [
    // Disabled until the server and the dedicated Microsoft reader are provisioned.
    'enabled' => env('TRUNKRS_ENABLED', false),
    'allow_local_read' => env('TRUNKRS_ALLOW_LOCAL_READ', false),
    'tenant_id' => env('TRUNKRS_TENANT_ID'),
    'client_id' => env('TRUNKRS_CLIENT_ID'),
    'reader_user_id' => env('TRUNKRS_READER_USER_ID'),
    'mailbox' => env('TRUNKRS_MAILBOX'),
    'folder_id' => env('TRUNKRS_FOLDER_ID'),
    'sender' => 'data@trunkrs.nl',
    'subject' => 'Not Delivered Shipments Yesterday - BBQuality',
    'folder_label' => 'Postvak IN / Klantenservice / Trunkrs not deliverd',
    'timezone' => 'Europe/Amsterdam',
    // Operational expectation, not a carrier delivery guarantee.
    'expected_by' => '07:00',
    'lookback_days' => 30,
];
