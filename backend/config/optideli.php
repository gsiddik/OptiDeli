<?php
return [
    'deployment_mode' => env('OPTIDELI_DEPLOYMENT_MODE', 'standalone'),
    'identity_mode' => env('OPTIDELI_IDENTITY_MODE', 'local'),
    'standalone_tenant' => env('OPTIDELI_STANDALONE_TENANT', 'demo'),
];
