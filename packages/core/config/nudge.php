<?php

return [

    // The host app's user model. It must use the Gerenuk\Nudge\Models\Concerns\HasWorkspaces trait.
    'user_model' => 'App\\Models\\User',

    'web_middleware' => ['web', 'auth', 'verified'],

    'api_prefix' => 'api/v1',

    'api_requests_per_minute' => (int) env('NUDGE_API_REQUESTS_PER_MINUTE', 120),

    // Serves the API reference at {api_prefix}/docs and the spec at {api_prefix}/openapi.json.
    'api_docs' => [
        'enabled' => (bool) env('NUDGE_API_DOCS', true),
    ],

    // Bodies are kept only for writes and errors, and are cleared sooner than the rows because they hold contact data.
    'api_log' => [
        'enabled' => (bool) env('NUDGE_API_LOG', true),
        'retention_days' => (int) env('NUDGE_API_LOG_DAYS', 30),
        'body_retention_days' => (int) env('NUDGE_API_LOG_BODY_DAYS', 7),
        'max_body_bytes' => 16384,
    ],

    'imports' => [
        'disk' => env('NUDGE_IMPORTS_DISK', 'local'),
        'max_file_kilobytes' => 51200,
        'chunk_size' => 500,
        'queue' => env('NUDGE_IMPORTS_QUEUE', 'default'),
    ],

    // Inboxes load email builder images straight from this disk, so it must be public.
    // On the default "public" disk, run `php artisan storage:link` once.
    'email_assets' => [
        'disk' => env('NUDGE_EMAIL_ASSETS_DISK', 'public'),
        'max_file_kilobytes' => 5120,
    ],

    // Serves tracking links and the unsubscribe page from another domain when set.
    'tracking_url' => env('NUDGE_TRACKING_URL'),

    // Old APP_KEYs that still verify links in emails already sent, and nothing else. See DEPLOYING.md.
    'previous_link_keys' => array_values(array_filter(explode(',', (string) env('NUDGE_PREVIOUS_LINK_KEYS', '')))),

    // The queue connection's `retry_after` must exceed the longest job timeout (imports run up to an hour),
    // or a second worker picks up long jobs while they're still running.
    'sending' => [
        'queue' => env('NUDGE_SENDING_QUEUE', 'default'),

        // Set to false on shared installs so workspace owners can't point SMTP at internal services.
        'allow_private_smtp_hosts' => (bool) env('NUDGE_ALLOW_PRIVATE_SMTP_HOSTS', true),

        // Pauses a workspace's sending when its bounce or complaint rate over the last week passes these limits.
        'auto_pause' => [
            'minimum_sends' => 200,
            'bounce_rate' => 0.05,
            'complaint_rate' => 0.003,
        ],
    ],

    'invitation_expiry_days' => 7,

    // Rows a dashboard table shows before anyone picks another size: one of 10, 25, 50 or 100.
    'tables' => [
        'per_page' => (int) env('NUDGE_TABLE_PER_PAGE', 10),
    ],

];
