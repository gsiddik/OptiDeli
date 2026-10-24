<?php

use App\Domain\Access\SystemRoles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('optideli:sync-access', function (SystemRoles $roles) {
    $roles->syncAll();
    $this->info('Permissions, system roles and first role assignments are in sync.');
})->purpose('Sync the permission catalog and each tenant\'s system roles');
