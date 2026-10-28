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
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitation_expiry_days' => 7,

];
