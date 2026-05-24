<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini API key
    |--------------------------------------------------------------------------
    |
    | Required for the per-fixture commentary endpoint. Obtain one from
    | https://aistudio.google.com/app/apikey. When unset, the commentary
    | endpoint returns 503 and nothing in the rest of the app changes.
    |
    */
    'api_key' => env('GEMINI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Gemini model
    |--------------------------------------------------------------------------
    |
    | Override with GEMINI_MODEL if you have access to a different Gemini
    | model. The default is gemini-2.5-flash because it's covered by Google's
    | free tier. gemini-2.5-pro requires a paid billing project. Any model
    | that speaks the v1beta generateContent API will work without code
    | changes.
    |
    */
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
];
