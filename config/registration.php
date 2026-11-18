<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration Mode
    |--------------------------------------------------------------------------
    |
    | Who can create an account. "invite" (the default) allows only email
    | addresses with a pending workspace invitation, "closed" allows nobody,
    | and "open" allows anyone. The first user of a new installation needs
    | the setup token below instead, whatever the mode.
    |
    */

    'mode' => env('REGISTRATION_MODE', 'invite'),

    /*
    |--------------------------------------------------------------------------
    | Setup Token
    |--------------------------------------------------------------------------
    |
    | Required to create the first account through the website. Leave empty
    | to create it with `php artisan app:create-owner` instead.
    |
    */

    'setup_token' => env('SETUP_TOKEN'),

];
