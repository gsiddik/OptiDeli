<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionFreightTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local']);
        $this->tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $this->tenant, 'slug' => 'p2', 'name' => 'P2']);
        $this->asRole('dispatcher');
    }

    private function asRole(string $role): User
    {
        if (! isset($this->users[$role])) {
            $u = User::factory()->create();
            DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $u->id, 'role' => $role, 'active' => true]);
            $this->users[$role] = $u;
        }$this->actingAs($this->users[$role])->withSession(['tenant_id' => $this->tenant]);

        return $this->users[$role];
    }

    private function create(string $path, array $data)
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function file(string $name = 'proof.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 2, 'application/pdf');
    }

    private function fixture(bool $complete = false): array
    {
        $this->asRole('dispatcher');
        $o = $this->create('orders', ['customer_name' => 'Fixture', 'stage' => 'MIDDLE_MILE', 'origin' => 'A', 'destination' => 'B', 'currency' => 'IDR', 'base_price_minor' => 2000000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'items' => [['description' => 'Box', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]]])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders/'.$o.'/transition', ['action' => 'confirm'])->assertOk();
        $v = (string) Str::uuid();
        $d = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $v, 'tenant_id' => $this->tenant, 'plate' => Str::random(8), 'type' => 'Box', 'capacity_g' => 1000000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
        DB::table('drivers')->insert(['id' => $d, 'tenant_id' => $this->tenant, 'name' => 'Driver', 'license_number' => Str::random(10), 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDay(), 'active' => true]);
        $plan = $this->create('dispatch/plans', ['order_id' => $o, 'vehicle_id' => $v, 'driver_id' => $d, 'vendor_base_minor' => 1600000000, 'vendor_accessorial_minor' => 0, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(), 'load_safety_attestation' => 'Load inspection and securement verified by operator.'])->assertCreated()->json('data.id');
        $trip = $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk()->json('data.id');
        $vendor = $this->create('vendors', ['name' => 'Vendor', 'code' => Str::random(8)])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/trips/'.$trip.'/vendor', ['vendor_id' => $vendor, 'contract_reference' => 'CONTRACT-TEST'])->assertOk();
        if ($complete) {
            $leg = $this->postJson('/api/v1/trips/'.$trip.'/legs', ['origin' => 'A', 'destination' => 'B', 'planned_eta' => now()->addHours(4)->toIso8601String()])->assertCreated()->json('data.id');
            $this->postJson('/api/v1/trips/'.$trip.'/transition', ['action' => 'start'])->assertOk();
            $this->postJson('/api/v1/legs/'.$leg.'/transition', ['action' => 'depart'])->assertOk();
            $this->postJson('/api/v1/legs/'.$leg.'/transition', ['action' => 'arrive'])->assertOk();
            $pod = $this->post('/api/v1/trips/'.$trip.'/pods', ['receiver_name' => 'Receiver', 'delivered_units' => 1, 'outcome' => 'DELIVERED', 'file' => $this->file()], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
            $this->asRole('supervisor');
            $this->postJson('/api/v1/pods/'.$pod.'/verify', ['decision' => 'verify', 'note' => 'Delivery evidence checked independently.'])->assertOk();
            $this->asRole('dispatcher');
            $this->postJson('/api/v1/trips/'.$trip.'/transition', ['action' => 'complete'])->assertOk();
        }

        return compact('trip', 'vendor', 'o', 'v', 'd');
    }

    private function invoice(array $f, int $base = 1600000000, int $extra = 0, int $round = 0)
    {
        $this->asRole('finance');

        return $this->post('/api/v1/freight/invoices', ['trip_id' => $f['trip'], 'number' => 'INV-'.Str::random(8), 'base_minor' => $base, 'accessorial_minor' => $extra, 'rounding_minor' => $round, 'document_reference' => 'Supplier invoice document', 'file' => $this->file('invoice.pdf')], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]);
    }

    private function claim(array $f, int $amount): string
    {
        $this->asRole('dispatcher');

        return $this->create('freight/claims', ['trip_id' => $f['trip'], 'category' => 'DETENTION', 'amount_minor' => $amount, 'reason' => 'Waiting at the hub with supporting evidence.', 'evidence_reference' => 'Yard event report reference'])->assertCreated()->json('data.id');
    }

    private function verifyClaim(string $id, string $decision = 'verify'): void
    {
        $this->asRole('finance');
        $this->postJson('/api/v1/freight/claims/'.$id.'/verify', ['decision' => $decision, 'note' => 'Evidence checked independently by Finance.'])->assertOk();
    }

    public function test_completion_requires_verified_pod_and_releases_active_resources(): void
    {
        $f = $this->fixture(true);
        $this->assertDatabaseHas('trips', ['id' => $f['trip'], 'status' => 'COMPLETED', 'active' => false]);
        $this->assertDatabaseHas('orders', ['id' => $f['o'], 'status' => 'DELIVERED']);
        $this->getJson('/api/v1/fleet')->assertJsonCount(0, 'data.allocations');
        $this->assertDatabaseHas('outbox_events', ['type' => 'trip.complete.v1']);
    }

    public function test_trip_cannot_complete_or_depart_legs_out_of_order(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/trips/'.$f['trip'].'/transition', ['action' => 'complete'])->assertConflict();
        $legs = [];
        foreach ([['A', 'Hub', 2], ['Hub', 'B', 4]] as [$a,$b,$hour]) {
            $legs[] = $this->postJson('/api/v1/trips/'.$f['trip'].'/legs', ['origin' => $a, 'destination' => $b, 'planned_eta' => now()->addHours($hour)->toIso8601String()])->assertCreated()->json('data.id');
        }$this->postJson('/api/v1/trips/'.$f['trip'].'/transition', ['action' => 'start'])->assertOk();
        $this->postJson('/api/v1/legs/'.$legs[1].'/transition', ['action' => 'depart'])->assertConflict();
        $this->postJson('/api/v1/legs/'.$legs[0].'/transition', ['action' => 'depart'])->assertOk();
        $this->postJson('/api/v1/legs/'.$legs[0].'/transition', ['action' => 'arrive'])->assertOk();
        $this->postJson('/api/v1/legs/'.$legs[1].'/transition', ['action' => 'depart'])->assertOk();
        $this->postJson('/api/v1/legs/'.$legs[1].'/transition', ['action' => 'arrive'])->assertOk();
        $this->postJson('/api/v1/trips/'.$f['trip'].'/transition', ['action' => 'complete'])->assertUnprocessable();
    }

    public function test_cancel_before_departure_preserves_history_and_returns_order_for_dispatch(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/trips/'.$f['trip'].'/transition', ['action' => 'cancel', 'reason' => 'Customer rescheduled before truck departure.'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $f['o'], 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('trips', ['id' => $f['trip'], 'active' => false]);
        $plan = $this->create('dispatch/plans', ['order_id' => $f['o'], 'vehicle_id' => $f['v'], 'driver_id' => $f['d'], 'vendor_base_minor' => 1600000000, 'vendor_accessorial_minor' => 0, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(4)->toIso8601String(), 'load_safety_attestation' => 'Load safety rechecked before new dispatch.'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk();
        $this->assertDatabaseCount('trips', 2);
    }

    public function test_dock_intervals_are_half_open_and_gate_sequence_is_enforced(): void
    {
        $f = $this->fixture();
        $g = $this->fixture();
        $dock = $this->postJson('/api/v1/yard/docks', ['name' => 'Dock 1', 'hub' => 'Hub A'])->assertCreated()->json('data.id');
        $data = ['dock_id' => $dock, 'trip_id' => $f['trip'], 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()];
        $a = $this->create('yard/appointments', $data)->assertCreated()->json('data.id');
        $data['trip_id'] = $g['trip'];
        $this->create('yard/appointments', $data)->assertConflict();
        $data['starts_at'] = now()->addHours(2)->toIso8601String();
        $data['ends_at'] = now()->addHours(3)->toIso8601String();
        $this->create('yard/appointments', $data)->assertCreated();
        $this->postJson('/api/v1/yard/appointments/'.$a.'/transition', ['action' => 'dock_out'])->assertConflict();
        foreach (['gate_in', 'dock_in', 'dock_out', 'gate_out'] as $action) {
            $this->postJson('/api/v1/yard/appointments/'.$a.'/transition', compact('action'))->assertOk();
        }
    }

    public function test_exact_match_is_ready_only_after_delivery_proof(): void
    {
        $f = $this->fixture();
        $i = $this->invoice($f)->assertCreated()->assertJsonPath('data.status', 'ON_HOLD')->assertJsonPath('data.hold_reason', 'DELIVERY_EVIDENCE_REQUIRED');
        $g = $this->fixture(true);
        $this->invoice($g)->assertCreated()->assertJsonPath('data.status', 'READY_FOR_PAYMENT');
    }

    public function test_base_rate_difference_always_publishes_correction_and_cannot_be_overridden(): void
    {
        $f = $this->fixture(true);
        $i = $this->invoice($f, 1600000100)->assertCreated()->assertJsonPath('data.hold_reason', 'BASE_RATE_MISMATCH')->json('data.id');
        $dispute = DB::table('freight_disputes')->where('invoice_id', $i)->first();
        $this->assertNotNull($dispute);
        $this->asRole('cfo');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', ['version' => 1, 'note' => 'CFO tries to bypass base price mismatch.'])->assertConflict();
        $this->postJson('/api/v1/freight/disputes/'.$dispute->id.'/action', ['action' => 'resolve', 'note' => 'Attempt manual base rate waiver.'])->assertConflict();
        $this->asRole('finance');
        $this->post('/api/v1/freight/invoices/'.$i.'/correct', ['trip_id' => $f['trip'], 'number' => 'CORRECTED', 'base_minor' => 1600000000, 'accessorial_minor' => 0, 'rounding_minor' => 0, 'document_reference' => 'Corrected supplier invoice', 'file' => $this->file()], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'READY_FOR_PAYMENT');
        $this->assertDatabaseHas('freight_invoices', ['id' => $i, 'status' => 'VOID']);
    }

    public function test_minor_rounding_at_ten_thousand_requires_independent_finance(): void
    {
        $f = $this->fixture(true);
        $i = $this->invoice($f, 1600000000, 0, 1000000)->assertCreated()->assertJsonPath('data.approval_tier', 'FINANCE')->json('data.id');
        $body = ['version' => 1, 'note' => 'Finance approves validated minor rounding.'];
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertForbidden();
        $this->asRole('supervisor');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertForbidden();
        $this->asRole('finance_supervisor');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertOk()->assertJsonPath('data.status', 'READY_FOR_PAYMENT');
    }

    public function test_exact_two_million_requires_two_distinct_authorities(): void
    {
        $f = $this->fixture(true);
        $this->verifyClaim($this->claim($f, 200000000));
        $i = $this->invoice($f, 1600000000, 200000000)->assertCreated()->assertJsonPath('data.approval_tier', 'DUAL')->json('data.id');
        $body = ['version' => 1, 'note' => 'Evidence and accessorial cost reviewed.'];
        $this->asRole('finance_supervisor');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertOk()->assertJsonPath('data.status', 'ON_HOLD');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertConflict();
        $this->asRole('head_of_logistics');
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', $body)->assertOk()->assertJsonPath('data.status', 'READY_FOR_PAYMENT');
    }

    public function test_over_two_million_requires_cfo_dispute_resolution_then_approval(): void
    {
        $f = $this->fixture(true);
        $this->verifyClaim($this->claim($f, 200000001));
        $i = $this->invoice($f, 1600000000, 200000001)->assertCreated()->assertJsonPath('data.approval_tier', 'CFO')->assertJsonPath('data.hold_reason', 'DISPUTE_OPEN')->json('data.id');
        $d = DB::table('freight_disputes')->where('invoice_id', $i)->first();
        $this->postJson('/api/v1/freight/disputes/'.$d->id.'/action', ['action' => 'resolve', 'note' => 'Finance attempts high value resolution.'])->assertForbidden();
        $this->asRole('cfo');
        $this->postJson('/api/v1/freight/disputes/'.$d->id.'/action', ['action' => 'resolve', 'note' => 'CFO reviewed evidence and accepted commercial risk.'])->assertOk();
        $this->postJson('/api/v1/freight/invoices/'.$i.'/approve', ['version' => 1, 'note' => 'CFO approves after formal dispute review.'])->assertOk()->assertJsonPath('data.status', 'READY_FOR_PAYMENT');
    }

    public function test_late_accessorial_immediately_reholds_and_invalidates_approvals(): void
    {
        $f = $this->fixture(true);
        $i = $this->invoice($f)->assertCreated()->json('data.id');
        $claim = $this->claim($f, 100000);
        $this->assertDatabaseHas('freight_invoices', ['id' => $i, 'status' => 'ON_HOLD', 'version' => 2]);
        $this->assertDatabaseHas('outbox_events', ['type' => 'freight.settlement_held.v1']);
        $this->verifyClaim($claim, 'reject');
        $this->assertDatabaseHas('freight_invoices', ['id' => $i, 'status' => 'READY_FOR_PAYMENT', 'version' => 3]);
    }

    public function test_vendor_cannot_read_other_vendor_or_internal_data(): void
    {
        $f = $this->fixture(true);
        $i = $this->invoice($f)->assertCreated()->json('data.id');
        $user = $this->asRole('vendor');
        $other = $this->users['dispatcher'];
        $v = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $v, 'tenant_id' => $this->tenant, 'name' => 'Other', 'code' => 'OTHER']);
        DB::table('memberships')->where('user_id', $user->id)->update(['vendor_id' => $v]);
        $this->getJson('/api/v1/vendor/portal')->assertOk()->assertJsonCount(0, 'data.invoices');
        $this->getJson('/api/v1/vendor/invoices/'.$i)->assertNotFound();
        $this->getJson('/api/v1/files/invoices/'.$i)->assertNotFound();
        $this->getJson('/api/v1/orders')->assertForbidden();
        $this->getJson('/api/v1/trips/'.$f['trip'])->assertForbidden();
    }

    public function test_finance_cannot_dispatch_and_viewer_cannot_create_claims(): void
    {
        $f = $this->fixture();
        $this->asRole('finance');
        $this->postJson('/api/v1/trips/'.$f['trip'].'/transition',['action' => 'cancel', 'reason' => 'Finance cannot act as dispatcher.'])->assertForbidden();
        $this->asRole('viewer');
        $this->create('freight/claims',['trip_id' => $f['trip']])->assertForbidden();
    }
}
