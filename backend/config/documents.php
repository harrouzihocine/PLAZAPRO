<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Generated document branding
    |--------------------------------------------------------------------------
    | Company details stamped onto generated PDFs (receipts, contracts, quotes).
    | Overridable per-deployment via env. Purely presentational — figures are
    | always snapshotted into documents.meta at generation time.
    */

    'company' => [
        'name' => env('COMPANY_NAME', 'PLAZA PRO'),
        'tagline' => env('COMPANY_TAGLINE', 'Real Estate'),
        'address' => env('COMPANY_ADDRESS', ''),
        'phone' => env('COMPANY_PHONE', ''),
        'email' => env('COMPANY_EMAIL', ''),
        'currency' => env('COMPANY_CURRENCY', 'DZD'),
    ],

];
