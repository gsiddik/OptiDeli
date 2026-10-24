<?php

use App\Domain\Access\SystemRoles;
use App\Domain\Integration\Optinexus\OptinexusManifest;
use App\Domain\Ledger\FeedCredentials;
use App\Domain\Platform\PlatformAccess;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('optideli:sync-access', function (SystemRoles $roles) {
    $roles->syncAll();
    $this->info('Permissions, system roles and first role assignments are in sync.');
})->purpose('Sync the permission catalog and each tenant\'s system roles');

Artisan::command('optideli:create-platform-admin {email} {--name=Platform Administrator}', function (string $email) {
    // Bootstraps the first Superadmin. The password is asked interactively (or read from OPTIDELI_PLATFORM_ADMIN_PASSWORD) and never seeded.
    PlatformAccess::sync();
    $password = getenv('OPTIDELI_PLATFORM_ADMIN_PASSWORD') ?: $this->secret('Password (min. 12, upper/lower case, number, symbol)');
    $validator = Validator::make(['email' => $email, 'password' => $password], ['email' => 'required|email|max:254', 'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
    if ($validator->fails()) {
        $this->error(implode(' ', $validator->errors()->all()));

        return 1;
    }
    $user = User::firstOrCreate(['email' => strtolower($email)], ['name' => $this->option('name'), 'password' => $password]);
    $role = DB::table('platform_roles')->where('name', 'platform_administrator')->value('id');
    DB::table('platform_role_assignments')->insertOrIgnore(['user_id' => $user->id, 'platform_role_id' => $role, 'created_at' => now()]);
    PlatformAccess::audit(null, 'platform.admin_bootstrapped', (string) $user->id, ['email' => $user->email]);
    $this->info("{$user->email} is a platform administrator.");

    return 0;
})->purpose('Create or promote the platform administrator (Superadmin)');

Artisan::command('optideli:nexus:manifest {--output= : Write the JSON to this file instead of printing it}', function (OptinexusManifest $manifest) {
    // What an OptiNexus operator imports and registers for OptiDeli (docs/architecture/OPTINEXUS_ADAPTER.md §6). Contains no secret.
    $json = json_encode($manifest->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($path = $this->option('output')) {
        file_put_contents($path, $json.PHP_EOL);
        $this->info("Manifest written to {$path}.");

        return 0;
    }
    $this->line($json);

    return 0;
})->purpose('Print the OptiDeli manifest v2 for OptiNexus');

Artisan::command('optideli:fleet:token {tenant : Tenant slug or id} {--token-file= : File holding the OptiFleet availability token (never pass it as an argument)} {--clear : Remove the stored token}', function (string $tenant) {
    // The tenant's OptiFleet read credential (docs/architecture/FLEET_AVAILABILITY.md). Stored encrypted; no API ever returns it.
    $row = DB::table('tenants')->where(fn ($q) => $q->where('slug', $tenant)->orWhere('id', Str::isUuid($tenant) ? $tenant : null))->first();
    if (! $row) {
        $this->error("Tenant '{$tenant}' not found.");

        return 1;
    }
    if ($this->option('clear')) {
        DB::table('tenants')->where('id', $row->id)->update(['fleet_availability_token' => null, 'updated_at' => now()]);
        PlatformAccess::audit(null, 'platform.fleet_token_cleared', $row->id, []);
        $this->info("OptiFleet token removed for {$row->slug}.");

        return 0;
    }
    $file = (string) $this->option('token-file');
    $token = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if ($token === '' || ! str_starts_with($token, 'ofaf_')) {
        $this->error('Give --token-file with an OptiFleet availability token (starts with ofaf_).');

        return 1;
    }
    DB::table('tenants')->where('id', $row->id)->update(['fleet_availability_token' => Crypt::encryptString($token), 'updated_at' => now()]);
    PlatformAccess::audit(null, 'platform.fleet_token_set', $row->id, []);
    $this->info("OptiFleet token stored (encrypted) for {$row->slug}.");

    return 0;
})->purpose('Store or remove a tenant\'s OptiFleet availability credential');

Artisan::command('optideli:feed:issue-credential {tenant : Tenant slug or id} {--consumer=optientry} {--name=OptiEntry feed} {--expires= : Expiry date (Y-m-d)} {--output= : Write the token to this file (mode 600) instead of printing it}', function (FeedCredentials $credentials, string $tenant) {
    // Opti Integration Feed v1 read credential (docs/integration/OPTI_INTEGRATION_FEED_V1.md). The token is shown once; only its hash is stored.
    $row = DB::table('tenants')->where(fn ($q) => $q->where('slug', $tenant)->orWhere('id', Str::isUuid($tenant) ? $tenant : null))->first();
    if (! $row) {
        $this->error("Tenant '{$tenant}' not found.");

        return 1;
    }
    $issued = $credentials->issue($row->id, (string) $this->option('consumer'), (string) $this->option('name'), 'cli', $this->option('expires') ?: null);
    PlatformAccess::audit(null, 'platform.feed_credential_issued', $row->id, ['credential_id' => $issued['id'], 'consumer' => $this->option('consumer')]);
    if ($path = $this->option('output')) {
        file_put_contents($path, $issued['token'].PHP_EOL);
        chmod($path, 0600);
        $this->info("Credential {$issued['id']} issued for {$row->slug}; token written to {$path}.");

        return 0;
    }
    $this->info("Credential {$issued['id']} issued for {$row->slug}. Token (shown once):");
    $this->line($issued['token']);

    return 0;
})->purpose('Issue an Opti Integration Feed read credential for a tenant');

Artisan::command('optideli:feed:revoke-credential {id} {--reason=revoked by operator}', function (FeedCredentials $credentials, string $id) {
    if (! $credentials->revoke($id, (string) $this->option('reason'))) {
        $this->error('Credential not found or already revoked.');

        return 1;
    }
    $tenantId = DB::table('integration_feed_credentials')->where('id', $id)->value('tenant_id');
    PlatformAccess::audit(null, 'platform.feed_credential_revoked', $tenantId, ['credential_id' => $id]);
    $this->info('Credential revoked.');

    return 0;
})->purpose('Revoke an Opti Integration Feed credential');
