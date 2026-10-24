<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Mode: 'standalone' vs 'saas'
    |--------------------------------------------------------------------------
    */
    'deployment_mode' => env('OPTIDELI_DEPLOYMENT_MODE', 'standalone'),

    /*
    | Login identity mode read by AuthController and TenantContext: 'local' (users, roles and permissions in
    | this database) or 'optinexus' (sign-in through OptiNexus OIDC, permissions from OptiNexus). Any other
    | value makes login and tenant routes answer 503 instead of failing open.
    | In standalone deployment only the tenant with this slug may sign in.
    */
    'identity_mode' => env('OPTIDELI_IDENTITY_MODE', 'local'),
    'standalone_tenant' => env('OPTIDELI_STANDALONE_TENANT', 'demo'),

    /*
    |--------------------------------------------------------------------------
    | Identity & Access Management Driver
    | Options: 'local' (Local DB users/roles) | 'optinexus' (SSO & Central CGO)
    |--------------------------------------------------------------------------
    */
    'identity' => [
        'driver' => env('OPTIDELI_IDENTITY_MODE', 'local'),
    ],

    /*
    | OptiNexus adapter, used when identity_mode is 'optinexus' (docs/architecture/OPTINEXUS_ADAPTER.md).
    | Secrets come from the environment only.
    */
    'optinexus' => [
        'base_url' => rtrim((string) env('OPTINEXUS_BASE_URL', ''), '/'),
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optideli'),
        // Seconds a pulled permission answer is reused; never more than 300 or than the ttl OptiNexus sends.
        'permission_ttl_seconds' => min(300, max(0, (int) env('OPTINEXUS_PERMISSION_TTL', 300))),
        'sso' => [
            'client_id' => env('OPTINEXUS_SSO_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SSO_CLIENT_SECRET'),
            // Defaults to {APP_URL}/api/v1/auth/sso/callback; must be registered exactly on the OIDC client.
            'redirect_uri' => env('OPTINEXUS_SSO_REDIRECT_URI'),
            // Where the SPA runs; the callback lands the browser there and OptiNexus returns there after logout.
            'frontend_url' => rtrim((string) env('OPTIDELI_FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
            'state_ttl_seconds' => 600,
        ],
        'service' => [
            'client_id' => env('OPTINEXUS_SERVICE_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SERVICE_CLIENT_SECRET'),
            'timeout_seconds' => (int) env('OPTINEXUS_TIMEOUT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telematics & IoT Ingestion Driver
    | Options: 'generic' (Standard IoT Ping) | 'optiradar' (OptiRadar Feed) | 'none'
    |--------------------------------------------------------------------------
    */
    'telematics' => [
        'driver' => env('TELEMATICS_DRIVER', 'generic'),
        'optiradar_url' => env('OPTIRADAR_API_URL', 'http://localhost:8002'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet Maintenance & Asset Lock Driver
    | Options: 'local' (OptiDeli DB) | 'optifleet' (OptiFleet VMS) | 'none'
    |--------------------------------------------------------------------------
    */
    'fleet' => [
        'driver' => env('FLEET_SERVICE_DRIVER', 'local'),
        'optifleet_url' => env('OPTIFLEET_API_URL', 'http://localhost:8001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounting & Financial Settlement Driver
    | Options: 'internal' (OptiDeli Ledger) | 'optientry' (OptiAccounting) | 'edi210' | 'none'
    |--------------------------------------------------------------------------
    */
    'accounting' => [
        'driver' => env('ACCOUNTING_DRIVER', 'internal'),
        'optientry_url' => env('OPTIACCOUNTING_API_URL', 'http://localhost:8003'),
    ],
];
