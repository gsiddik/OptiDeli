<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seed is local/testing only.');
        }
        $password = getenv('OPTIDELI_DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Set OPTIDELI_DEMO_PASSWORD (at least 12 characters), never a production credential.');
        }
        DB::transaction(function () use ($password) {
            $tenant = DB::table('tenants')->where('slug', 'demo')->first();
            $id = $tenant?->id ?? (string) Str::uuid();
            if (! $tenant) {
                DB::table('tenants')->insert(['id' => $id, 'slug' => 'demo', 'name' => 'OptiDeli Demo', 'minimum_margin_bps' => 1500, 'policy_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (['dispatcher', 'supervisor', 'head_of_logistics', 'cfo', 'viewer', 'finance', 'finance_supervisor'] as $role) {
                $user = User::firstOrCreate(['email' => $role.'@optideli.test'], ['name' => ucwords(str_replace('_', ' ', $role)), 'password' => Hash::make($password)]);
                DB::table('memberships')->updateOrInsert(['tenant_id' => $id, 'user_id' => $user->id], ['role' => $role, 'active' => true]);
            }
        });
    }
}
