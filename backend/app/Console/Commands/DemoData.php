<?php

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Fleet\VendorFleet;
use App\Domain\Integration\Master\VehicleMaster;
use App\Domain\Integration\Master\VendorMaster;
use App\Domain\Master\MasterService;
use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffService;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\OrderController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demo data of the OptiDeli part of the demo ecosystem (see `ecosystem:seed-demo` in OptiNexus): the customers, places, fleet types,
 * a transporter with a verified vehicle, the tenant's own vehicles and drivers, the transporter price mapping (own fleet and the
 * vendor), a contract tariff and customer margins, and three orders (priced automatically, with a vendor, waiting for a manual price).
 * Everything goes through the same controllers and services as the screens, so the vehicles are announced to OptiFleet and OptiRadar
 * by the real master events. Safe to run again: what exists is kept. Local installations only.
 */
class DemoData extends Command
{
    protected $signature = 'ecosystem:demo-data {--tenant-slug=demo-ekosistem : Slug of the tenant (the one `ecosystem:seed-demo` created)}';

    protected $description = 'Load the OptiDeli part of the demo ecosystem (masters, fleet, transporter prices, orders) into the demo tenant';

    private TenantStore $planner;

    private TenantStore $approver;

    private array $tenant = [];

    /** @var array<string, string> reference id by key */
    private array $id = [];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('ECOSYSTEM_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Demo data is for local installations. Set ECOSYSTEM_ALLOW_DEMO_SEED=true to allow it elsewhere.');

            return self::FAILURE;
        }
        $tenant = DB::table('tenants')->where('slug', $this->option('tenant-slug'))->first();
        if (! $tenant) {
            $this->error('Tenant not found in OptiDeli yet. Run ecosystem:seed-demo in OptiNexus first and wait for the assignment event.');

            return self::FAILURE;
        }
        $this->tenant = (array) $tenant;
        // Two seeding actors (no one can sign in as them): a planner and an approver, because a tariff is never approved by its author.
        $plannerUser = $this->actor('demo.perencana@demo-ekosistem.test', 'Perencana Demo', 'dispatcher');
        $approverUser = $this->actor('demo.penyetuju@demo-ekosistem.test', 'Penyetuju Demo', 'head_of_logistics');
        $this->planner = new TenantStore($tenant->id, $plannerUser->id);
        $this->approver = new TenantStore($tenant->id, $approverUser->id);
        DB::table('tenants')->where('id', $tenant->id)->update(['minimum_margin_bps' => 1500]);

        $this->masters();
        $this->fleet();
        $this->tariffs();
        $this->orders($plannerUser);
        $this->info('OptiDeli demo data ready for '.$tenant->name.'.');

        return self::SUCCESS;
    }

    private function actor(string $email, string $name, string $role): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make(Str::random(48))]);
        DB::table('memberships')->updateOrInsert(['tenant_id' => $this->tenant['id'], 'user_id' => $user->id], ['role' => $role, 'active' => true]);

        return $user;
    }

    /** The row of a master by code (or by name for customers, whose code is generated), created when missing. */
    private function master(string $resource, string $key, array $data, string $by = 'code'): string
    {
        $row = $this->planner->query($resource)->where($by, $data[$by])->first();
        $id = $row->id ?? (new MasterService($this->planner))->create($resource, $data)->id;
        $this->id[$key] = $id;

        return $id;
    }

    private function masters(): void
    {
        $region = $this->master('regions', 'r_jkt', ['code' => '31', 'name' => 'DKI Jakarta']);
        $west = $this->master('regions', 'r_jabar', ['code' => '32', 'name' => 'Jawa Barat']);
        $east = $this->master('regions', 'r_jatim', ['code' => '35', 'name' => 'Jawa Timur']);
        $this->master('cities', 'jkt', ['code' => '31.71', 'name' => 'Jakarta Pusat', 'region_id' => $region]);
        $this->master('cities', 'bdg', ['code' => '32.73', 'name' => 'Bandung', 'region_id' => $west]);
        $this->master('cities', 'sby', ['code' => '35.78', 'name' => 'Surabaya', 'region_id' => $east]);
        $this->master('customers', 'c1', ['name' => 'PT Maju Sejahtera Demo', 'payment_terms_days' => 30, 'pic_name' => 'Rina Maharani', 'pic_email' => 'rina@maju-sejahtera.example.test'], 'name');
        $this->master('customers', 'c2', ['name' => 'PT Nusantara Retail Demo', 'payment_terms_days' => 45, 'pic_name' => 'Agus Salim', 'pic_email' => 'agus@nusantara-retail.example.test'], 'name');
        $this->master('locations', 'wh_jkt', ['code' => 'DEMO-WH-JKT', 'name' => 'Gudang Utama Jakarta', 'type' => 'WAREHOUSE', 'city_id' => $this->id['jkt'], 'address' => 'Jl. Gudang Utama No. 1, Jakarta']);
        $this->master('locations', 'wh_bdg', ['code' => 'DEMO-WH-BDG', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE', 'city_id' => $this->id['bdg'], 'address' => 'Jl. Industri No. 8, Bandung']);
        $this->master('locations', 'st_sby', ['code' => 'DEMO-ST-SBY', 'name' => 'Toko Surabaya', 'type' => 'CUSTOMER', 'customer_id' => $this->id['c2'], 'city_id' => $this->id['sby'], 'address' => 'Jl. Raya Darmo No. 20, Surabaya']);
        $this->master('locations', 'pool', ['code' => 'DEMO-POOL', 'name' => 'Pool Kendaraan Jakarta', 'type' => 'POOL', 'city_id' => $this->id['jkt'], 'address' => 'Jl. Pool No. 3, Jakarta']);
        $this->master('carrier_types', 'cde', ['code' => 'CDE', 'name' => 'CDE (Colt Diesel Engkel)', 'capacity_g' => 2_500_000, 'length_mm' => 3_200, 'width_mm' => 1_700, 'height_mm' => 1_700]);
        $this->master('carrier_types', 'cdd', ['code' => 'CDD', 'name' => 'CDD (Colt Diesel Double)', 'capacity_g' => 5_000_000, 'length_mm' => 4_200, 'width_mm' => 2_000, 'height_mm' => 2_000]);
        foreach (['PALLET', 'DRUM', 'BOX', 'PCS'] as $code) {
            $this->id['u_'.$code] = (string) $this->planner->query('units')->where('code', $code)->value('id');
        }
        foreach (['FREIGHT'] as $code) {
            $this->id[$code] = (string) $this->planner->query('charge_codes')->where('code', $code)->value('id');
        }
        $vendor = $this->planner->query('vendors')->where('code', 'DEMO-VND')->first();
        if (! $vendor) {
            $id = (string) Str::uuid();
            DB::table('vendors')->insert(['id' => $id, 'tenant_id' => $this->tenant['id'], 'code' => 'DEMO-VND', 'name' => 'CV Transporter Demo', 'active' => true]);
        }
        $this->id['vendor'] = (string) $this->planner->query('vendors')->where('code', 'DEMO-VND')->value('id');
        $this->line('masters ok');
    }

    /** Calls a controller action as the planner would, in this process. */
    private function as(string $permissionsOf, TenantStore $who, string $method, string $uri, array $body, callable $action): mixed
    {
        $user = User::query()->findOrFail($who->actorId);
        $request = Request::create($uri, $method, $body, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()], json_encode($body));
        app()->instance('request', $request); // rebinding installs the auth resolver, so the user is set after it
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenant', (object) $this->tenant);
        $request->attributes->set('permissions', PermissionCatalog::templateCodes($permissionsOf));

        return $action($request);
    }

    private function fleet(): void
    {
        $fleet = new FleetController;
        $vehicles = [
            ['B 9001 DMO', 'cde', '860000000000901'],
            ['B 9002 DMO', 'cdd', '860000000000902'],
        ];
        foreach ($vehicles as [$plate, $type, $tracker]) {
            if ($this->planner->query('vehicles')->where('plate', $plate)->exists()) {
                $this->id['v_'.$plate] = (string) $this->planner->query('vehicles')->where('plate', $plate)->value('id');

                continue;
            }
            $body = ['plate' => $plate, 'type' => strtoupper($type), 'capacity_g' => $type === 'cde' ? 2_500_000 : 5_000_000, 'length_mm' => 4_000, 'width_mm' => 1_900, 'height_mm' => 1_900, 'documents_valid_until' => now()->addYear()->toDateString(), 'tracker_id' => $tracker];
            $created = $this->as('dispatcher', $this->planner, 'POST', '/api/v1/fleet/vehicles', $body, fn ($r) => $fleet->vehicle($r));
            $id = $created->getData(true)['data']['id'];
            $this->as('dispatcher', $this->planner, 'PUT', "/api/v1/fleet/vehicles/{$id}/transport", [
                'carrier_type_id' => $this->id[$type], 'capacity_g' => $body['capacity_g'], 'length_mm' => 4_000, 'width_mm' => 1_900, 'height_mm' => 1_900, 'documents_valid_until' => $body['documents_valid_until'], 'tracker_id' => $tracker,
            ], fn ($r) => $fleet->transport($r, $id));
            $this->id['v_'.$plate] = $id;
        }
        foreach ([['Budi Santoso', 'DEMO-SIM-0001'], ['Slamet Riyadi', 'DEMO-SIM-0002']] as [$name, $license]) {
            if ($this->planner->query('drivers')->whereRaw("regexp_replace(upper(license_number), '[^A-Z0-9]', '', 'g') = ?", [str_replace('-', '', $license)])->exists()) {
                continue;
            }
            $this->as('dispatcher', $this->planner, 'POST', '/api/v1/fleet/drivers', [
                'name' => $name, 'license_number' => $license, 'license_valid_until' => now()->addYears(2)->toDateString(), 'eligibility_verified_until' => now()->addDays(25)->toIso8601ZuluString(),
            ], fn ($r) => $fleet->driver($r));
        }
        // The transporter's verified vehicle: registered by the vendor, verified by the approver.
        $vendorVehicle = $this->planner->query('vehicles')->where('plate', 'B 9101 VND')->first();
        if (! $vendorVehicle) {
            $registered = (new VendorFleet($this->planner, $this->id['vendor']))->registerVehicle([
                'plate' => 'B 9101 VND', 'carrier_type_id' => $this->id['cde'], 'capacity_kg' => 2500, 'length_mm' => 3_200, 'width_mm' => 1_700, 'height_mm' => 1_700, 'tracker_id' => '860000000000911',
            ]);
            // A real verification needs the STNK and KIR scans; the demo vehicle is marked verified by the approver instead.
            $vendorVehicle = $registered;
        }
        if ($vendorVehicle->verification_status !== 'VERIFIED') {
            $registered = $vendorVehicle;
            DB::table('vehicles')->where('id', $registered->id)->update(['verification_status' => 'VERIFIED', 'verified_by' => $this->approver->actorId, 'verified_at' => now(), 'verification_note' => 'Data demo', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
            VendorMaster::publish($this->tenant['id'], 'VEHICLE', $registered->id);
        }
        $this->id['v_vendor'] = (string) $this->planner->query('vehicles')->where('plate', 'B 9101 VND')->value('id');
        // Announce the tenant's own vehicles again on every run: a platform that got its tenant after the first run (OptiFleet, at the
        // administrator's first sign-in or its own demo data) receives them now. The receiving side upserts, so repeating is harmless.
        foreach (['B 9001 DMO', 'B 9002 DMO'] as $plate) {
            VehicleMaster::publish($this->tenant['id'], $this->id['v_'.$plate]);
        }
        $this->line('fleet ok');
    }

    /** A BUY mapping row: [unit, fleet type key, lane origin city key, destination city key, initial rate (Rp), margin in basis points]. */
    private function tariffs(): void
    {
        $service = new TariffService($this->planner);
        $approver = new TariffService($this->approver);
        $lane = fn (string $o, string $d) => ['origin_city_id' => $this->id[$o], 'destination_city_id' => $this->id[$d]];
        $build = function (string $code, string $name, string $side, array $meta, array $rows, ?callable $after = null) use ($service, $approver) {
            if ($this->planner->query('rate_tables')->where('code', $code)->exists()) {
                return;
            }
            $table = $service->create($meta + ['code' => $code, 'name' => $name, 'side' => $side, 'valid_from' => now()->startOfYear()->toDateString()]);
            $lines = [];
            foreach ($rows as $row) {
                $lines[] = $service->addLine($table->id, $row);
            }
            if ($after) {
                $after($service, $table->id, $lines);
            }
            $approver->approve($table->id, 3);
        };
        $mapping = fn (string $unit, string $type, string $o, string $d, int $rate, int $bps) => $lane($o, $d) + [
            'charge_code_id' => $this->id['FREIGHT'], 'carrier_type_id' => $this->id[$type], 'basis' => 'UNIT', 'unit_id' => $this->id['u_'.$unit],
            'unit_price_minor' => $rate * 100, 'margin_mode' => 'PERCENT', 'margin_value' => $bps,
        ];
        $build('DEMO-BUY-OWN', 'Matriks armada sendiri', 'BUY', [], [
            $mapping('PALLET', 'cde', 'jkt', 'bdg', 900_000, 2200), $mapping('BOX', 'cde', 'jkt', 'bdg', 25_000, 2500), $mapping('PCS', 'cde', 'jkt', 'bdg', 4_000, 3000),
            $mapping('PALLET', 'cdd', 'jkt', 'bdg', 1_300_000, 2200), $mapping('PALLET', 'cde', 'jkt', 'sby', 2_400_000, 2000), $mapping('PALLET', 'cdd', 'jkt', 'sby', 3_300_000, 2000),
        ], function (TariffService $svc, string $tableId, array $lines) {
            // The second customer is given a thinner margin than the row's own (a margin that differs per customer).
            $svc->setLineMargin($tableId, $lines[0]->id, ['customer_id' => $this->id['c2'], 'margin_mode' => 'PERCENT', 'margin_value' => 1800]);
        });
        $build('DEMO-BUY-VND', 'Matriks CV Transporter Demo', 'BUY', ['vendor_id' => $this->id['vendor']], [
            $mapping('PALLET', 'cde', 'jkt', 'bdg', 800_000, 2500), $mapping('BOX', 'cde', 'jkt', 'bdg', 22_000, 2500), $mapping('PALLET', 'cde', 'jkt', 'sby', 2_100_000, 2200),
        ]);
        $this->line('buy mappings ok');
        $build('DEMO-SELL-KONTRAK', 'Kontrak PT Maju Sejahtera', 'SELL', ['customer_id' => $this->id['c1']], [
            $lane('jkt', 'bdg') + ['charge_code_id' => $this->id['FREIGHT'], 'carrier_type_id' => $this->id['cde'], 'basis' => 'UNIT', 'unit_id' => $this->id['u_PALLET'], 'unit_price_minor' => 1_050_000 * 100],
        ]);
        $this->line('contract tariff ok');
    }

    private function orders(User $planner): void
    {
        $orders = new OrderController;
        $item = fn (string $what, int $qty, string $unit) => ['description' => $what, 'quantity' => $qty, 'unit_weight_g' => 20_000, 'length_mm' => 1_200, 'width_mm' => 1_000, 'height_mm' => 1_100, 'stackable' => true, 'unit_id' => $this->id['u_'.$unit]];
        $make = function (string $ref, array $o) use ($orders) {
            $existing = $this->planner->query('orders')->where('reference', $ref)->first();
            if ($existing) {
                return (array) $existing;
            }

            return $this->as('dispatcher', $this->planner, 'POST', '/api/v1/orders', $o + [
                'price_mode' => 'MAPPING', 'stage' => 'MIDDLE_MILE', 'currency' => 'IDR', 'reference' => $ref, 'customer_id' => $this->id['c2'],
                'origin_location_id' => $this->id['wh_jkt'], 'destination_location_id' => $this->id['wh_bdg'],
            ], fn ($r) => $orders->create($r))->getData(true)['data'];
        };
        // 1. Own fleet, priced by the mapping and confirmed (the price is locked).
        $a = $make('DEMO-ORDER-1', ['transporter_vendor_id' => null, 'vehicle_id' => $this->id['v_B 9001 DMO'], 'items' => [$item('Pallet barang kemasan', 4, 'PALLET'), $item('Karton sampel', 30, 'BOX')]]);
        if ($a['status'] === 'DRAFT') {
            $this->as('dispatcher', $this->planner, 'POST', "/api/v1/orders/{$a['id']}/transition", ['action' => 'confirm'], fn ($r) => $orders->transition($r, $a['id']));
        }
        // 2. The customer with a contract: the contract price wins. Left as a draft.
        $make('DEMO-ORDER-2', ['customer_id' => $this->id['c1'], 'transporter_vendor_id' => null, 'vehicle_id' => $this->id['v_B 9001 DMO'], 'items' => [$item('Pallet obat umum', 6, 'PALLET')]]);
        // 3. The transporter's vehicle to Surabaya.
        $make('DEMO-ORDER-3', ['destination_location_id' => $this->id['st_sby'], 'transporter_vendor_id' => $this->id['vendor'], 'vehicle_id' => $this->id['v_vendor'], 'items' => [$item('Pallet minuman', 8, 'PALLET')]]);
        // 4. A drum has no row in the matrix: a manual price that waits for approval.
        try {
            $make('DEMO-ORDER-4', ['transporter_vendor_id' => null, 'vehicle_id' => $this->id['v_B 9002 DMO'], 'price_reason' => 'Drum belum ada di matriks harga; harga disepakati dengan pelanggan.', 'items' => [$item('Drum bahan baku', 5, 'DRUM') + ['manual_price_minor' => 2_750_000 * 100]]]);
        } catch (ValidationException $e) {
            $this->warn('order 4: '.json_encode($e->errors()));
        }
        $this->line('orders ok');
    }
}
