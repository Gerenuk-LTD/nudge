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

    // The API reference (`{api_prefix}/docs`) and its OpenAPI spec (`{api_prefix}/openapi.json`). The spec
    // describes open-source code, so it is public; set MARKETING_API_DOCS=false to turn both routes off.
    'api_docs' => [
        'enabled' => (bool) env('MARKETING_API_DOCS', true),
    ],

    // Every authenticated API request, shown under Workspace settings → API log. Bodies (cut to max_body_bytes) are
    // kept only for writes and errors, and cleared after body_retention_days because they hold contact data. The rows
    // themselves go after retention_days.
    'api_log' => [
        'enabled' => (bool) env('MARKETING_API_LOG', true),
        'retention_days' => (int) env('MARKETING_API_LOG_DAYS', 30),
        'body_retention_days' => (int) env('MARKETING_API_LOG_BODY_DAYS', 7),
        'max_body_bytes' => 16384,
    ],

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

    // Old APP_KEYs that still verify tracking and unsubscribe links in emails already sent, without being trusted for
    // anything else. Used after a key leak; see DEPLOYING.md.
    'previous_link_keys' => array_values(array_filter(explode(',', (string) env('MARKETING_PREVIOUS_LINK_KEYS', '')))),

    'sending' => [
        'queue' => env('MARKETING_SENDING_QUEUE', 'default'),

        // Self-hosted installs often relay through a local mail server. On a shared, multi-tenant
        // install, set this to false so workspace owners can't point SMTP at internal services.
        'allow_private_smtp_hosts' => (bool) env('MARKETING_ALLOW_PRIVATE_SMTP_HOSTS', true),

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
