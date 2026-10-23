<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Mode: 'standalone' vs 'saas'
    |--------------------------------------------------------------------------
    */
    'deployment_mode' => env('OPTIDELI_DEPLOYMENT_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Identity & Access Management Driver
    | Options: 'standalone' (Local DB users/roles) | 'optinexus' (SSO & Central CGO)
    |--------------------------------------------------------------------------
    */
    'identity' => [
        'driver' => env('OPTIDELI_IDENTITY_MODE', 'standalone'),
        'optinexus_url' => env('OPTINEXUS_API_URL', 'http://localhost:8000'),
        'm2m_client_id' => env('OPTIDELI_M2M_CLIENT_ID', 'optideli'),
        'm2m_client_secret' => env('OPTIDELI_M2M_CLIENT_SECRET', ''),
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
