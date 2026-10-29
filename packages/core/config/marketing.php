<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The host application owns users and authentication. Its user model must
    | use the Gerenuk\Marketing\Models\Concerns\HasWorkspaces trait.
    |
    */

    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'web_middleware' => ['web', 'auth', 'verified'],

    'api_prefix' => 'api/v1',

    'api_requests_per_minute' => (int) env('MARKETING_API_REQUESTS_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Imports
    |--------------------------------------------------------------------------
    */

    'imports' => [
        'disk' => env('MARKETING_IMPORTS_DISK', 'local'),
        'max_file_kilobytes' => 51200,
        'chunk_size' => 500,
        'queue' => env('MARKETING_IMPORTS_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Images
    |--------------------------------------------------------------------------
    |
    | Images uploaded in the email builder. Inboxes load them straight from
    | this disk, so it must serve files publicly; on the default "public" disk
    | run `php artisan storage:link` once.
    |
    */

    'email_assets' => [
        'disk' => env('MARKETING_EMAIL_ASSETS_DISK', 'public'),
        'max_file_kilobytes' => 5120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Tracking links and the unsubscribe page use tracking_url when set, so a
    | separate domain (or a service that stays up when the dashboard is down)
    | can serve them. Auto-pause stops a workspace's sending when its bounce or
    | complaint rate over the last week passes these limits.
    |
    | The host queue connection's `retry_after` must exceed the longest job
    | timeout (imports run up to an hour), or long jobs are handed to a second
    | worker while still running.
    |
    */

    'tracking_url' => env('MARKETING_TRACKING_URL'),

    'sending' => [
        'queue' => env('MARKETING_SENDING_QUEUE', 'default'),
        'auto_pause' => [
            'minimum_sends' => 200,
            'bounce_rate' => 0.05,
            'complaint_rate' => 0.003,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitation_expiry_days' => 7,

];
