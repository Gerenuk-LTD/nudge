<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration Mode
    |--------------------------------------------------------------------------
    |
    | Who can create an account. "invite" (the default) allows only email
    | addresses with a pending workspace invitation, "closed" allows nobody,
    | and "open" allows anyone. Whatever the mode, the first user of a new
    | installation can always register, so it can be set up.
    |
    */

    'mode' => env('REGISTRATION_MODE', 'invite'),

];
