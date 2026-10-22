<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransportWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private string $tenant;
    private User $dispatcher;
    protected function setUp(): void
    {
        parent::setUp();
        config(['optideli.deployment_mode'=>'saas','optideli.identity_mode'=>'local']);
        $this->tenant=(string)Str::uuid();
        DB::table('tenants')->insert(['id'=>$this->tenant,'slug'=>'test','name'=>'Test','minimum_margin_bps'=>1500,'policy_version'=>1]);
        $this->dispatcher=$this->member('dispatcher');
        $this->actingAs($this->dispatcher)->withSession(['tenant_id'=>$this->tenant]);
    }
    private function member(string $role): User
    {
        $user=User::factory()->create();
        DB::table('memberships')->insert(['tenant_id'=>$this->tenant,'user_id'=>$user->id,'role'=>$role,'active'=>true]);
        return $user;
    }
    private function orderData(): array
    {
        return ['customer_name'=>'PT Pelanggan','stage'=>'FIRST_MILE','origin'=>'Bekasi','destination'=>'Jakarta','currency'=>'IDR','base_price_minor'=>100000000,'surcharge_minor'=>0,'accessorial_minor'=>0,'items'=>[['description'=>'Paket','quantity'=>2,'unit_weight_g'=>10000,'length_mm'=>400,'width_mm'=>300,'height_mm'=>200,'stackable'=>false]]];
    }
    private function order(bool $confirm=true): string
    {
        $id=$this->postJson('/api/v1/orders',$this->orderData(),['Idempotency-Key'=>(string)Str::uuid()])->assertCreated()->json('data.id');
        if ($confirm) { $this->postJson('/api/v1/orders/'.$id.'/transition',['action'=>'confirm'])->assertOk(); }
        return $id;
    }
    private function planData(string $order,int $cost=80000000): array
    {
        $vehicle=(string)Str::uuid(); $driver=(string)Str::uuid();
        DB::table('vehicles')->insert(['id'=>$vehicle,'tenant_id'=>$this->tenant,'plate'=>'B '.Str::random(6),'type'=>'Box','capacity_g'=>1000000,'length_mm'=>4000,'width_mm'=>2000,'height_mm'=>2000,'status'=>'AVAILABLE','documents_valid_until'=>now()->addYear()->toDateString(),'readiness_checked_at'=>now()]);
        DB::table('drivers')->insert(['id'=>$driver,'tenant_id'=>$this->tenant,'name'=>'Pengemudi','license_number'=>Str::random(16),'license_valid_until'=>now()->addYear()->toDateString(),'eligibility_verified_until'=>now()->addDays(2),'active'=>true]);
        return ['order_id'=>$order,'vehicle_id'=>$vehicle,'driver_id'=>$driver,'vendor_base_minor'=>$cost,'vendor_accessorial_minor'=>0,'starts_at'=>now()->addHour()->toIso8601String(),'ends_at'=>now()->addHours(4)->toIso8601String(),'load_safety_attestation'=>'Pemeriksaan muatan, pengikatan dan distribusi beban telah dilakukan.'];
    }
    private function plan(array $data)
    {
        return $this->postJson('/api/v1/dispatch/plans',$data,['Idempotency-Key'=>(string)Str::uuid()]);
    }
    public function test_order_to_dispatch_is_atomic_and_retry_safe(): void
    {
        $order=$this->order(); $data=$this->planData($order); $key=(string)Str::uuid();
        $plan=$this->postJson('/api/v1/dispatch/plans',$data,['Idempotency-Key'=>$key])->assertCreated()->assertJsonPath('data.status','READY')->json('data.id');
        $this->postJson('/api/v1/dispatch/plans',$data,['Idempotency-Key'=>$key])->assertCreated()->assertJsonPath('data.id',$plan);
        $trip=$this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk()->json('data.id');
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk()->assertJsonPath('data.id',$trip);
        $this->assertDatabaseCount('trips',1);
        $this->assertDatabaseHas('orders',['id'=>$order,'status'=>'DISPATCHED']);
        $this->assertDatabaseHas('audit_events',['action'=>'trip.dispatched','subject_id'=>$trip]);
        $this->assertDatabaseHas('outbox_events',['type'=>'trip.dispatched.v1','published_at'=>null]);
    }
    public function test_order_idempotency_conflicts_on_changed_content(): void
    {
        $data=$this->orderData();$key=(string)Str::uuid();
        $this->postJson('/api/v1/orders',$data,['Idempotency-Key'=>$key])->assertCreated();
        $data['customer_name']='Changed';
        $this->postJson('/api/v1/orders',$data,['Idempotency-Key'=>$key])->assertConflict();
        $this->assertDatabaseCount('orders',1);
    }
    public function test_idempotency_key_and_positive_sell_are_required(): void
    {
        $this->postJson('/api/v1/orders',$this->orderData())->assertUnprocessable();
        $data=$this->orderData();$data['base_price_minor']=0;
        $this->postJson('/api/v1/orders',$data,['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('orders',0);
    }
    public function test_nested_tenant_injection_is_rejected(): void
    {
        $data=$this->orderData();$data['items'][0]['tenant_id']=(string)Str::uuid();
        $this->postJson('/api/v1/orders',$data,['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
    }
    public function test_tenant_isolation_for_reads_mutations_and_headers(): void
    {
        $id=$this->order(false); $other=(string)Str::uuid();
        DB::table('tenants')->insert(['id'=>$other,'slug'=>'other','name'=>'Other']);
        $user=User::factory()->create();DB::table('memberships')->insert(['tenant_id'=>$other,'user_id'=>$user->id,'role'=>'dispatcher']);
        $this->actingAs($user)->withSession(['tenant_id'=>$other]);
        $this->getJson('/api/v1/orders/'.$id,['X-Tenant-ID'=>$this->tenant])->assertNotFound();
        $this->getJson('/api/v1/orders')->assertJsonCount(0,'data');
        $this->postJson('/api/v1/orders/'.$id.'/transition',['action'=>'confirm'])->assertNotFound();
    }
    public function test_cross_tenant_vehicle_and_driver_are_not_assignable(): void
    {
        $id=$this->order();$data=$this->planData($id);$other=(string)Str::uuid();
        DB::table('tenants')->insert(['id'=>$other,'slug'=>'other','name'=>'Other']);
        DB::table('vehicles')->where('id',$data['vehicle_id'])->update(['tenant_id'=>$other]);
        $this->plan($data)->assertNotFound();$this->assertDatabaseCount('dispatch_plans',0);
    }
    public function test_revoked_membership_and_standalone_boundary_fail_closed(): void
    {
        config(['optideli.deployment_mode'=>'standalone','optideli.standalone_tenant'=>'not-test']);
        $this->getJson('/api/v1/orders')->assertForbidden();
        config(['optideli.deployment_mode'=>'saas']);
        DB::table('memberships')->where('user_id',$this->dispatcher->id)->update(['active'=>false]);
        $this->getJson('/api/v1/orders')->assertForbidden();
    }
    public function test_viewer_cannot_write_or_confirm(): void
    {
        $id=$this->order(false);$this->actingAs($this->member('viewer'));
        $this->postJson('/api/v1/orders',$this->orderData(),['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->postJson('/api/v1/orders/'.$id.'/transition',['action'=>'confirm'])->assertForbidden();
    }
    public function test_margin_requires_reason_code_and_authorized_independent_approval(): void
    {
        $data=$this->planData($this->order(),89000000);
        $this->plan($data)->assertUnprocessable();
        $data['reason_code']='BACKHAUL';
        $plan=$this->plan($data)->assertCreated()->assertJsonPath('data.required_level',1)->assertJsonPath('data.status','PENDING_APPROVAL')->json('data.id');
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertUnprocessable();
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/approve')->assertForbidden();
        $this->actingAs($this->member('supervisor'));
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/approve')->assertOk();
        $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk();
    }
    public function test_cfo_required_for_negative_margin(): void
    {
        $data=$this->planData($this->order(),110000000);$data['reason_code']='CUSTOMER_RETENTION';
        $id=$this->plan($data)->assertCreated()->assertJsonPath('data.required_level',3)->json('data.id');
        $this->actingAs($this->member('head_of_logistics'));
        $this->postJson('/api/v1/dispatch/plans/'.$id.'/approve')->assertForbidden();
        $this->actingAs($this->member('cfo'));
        $this->postJson('/api/v1/dispatch/plans/'.$id.'/approve')->assertOk();
    }
    public function test_requester_cannot_approve_own_request_even_as_cfo(): void
    {
        $this->actingAs($this->member('cfo'));
        $data=$this->planData($this->order(),98000000);$data['reason_code']='CONTRACT_COMMITMENT';
        $id=$this->plan($data)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/dispatch/plans/'.$id.'/approve')->assertForbidden();
    }
    public function test_policy_change_invalidates_approved_snapshot(): void
    {
        $id=$this->plan($this->planData($this->order()))->assertCreated()->json('data.id');
        DB::table('tenants')->where('id',$this->tenant)->update(['policy_version'=>2]);
        $this->postJson('/api/v1/dispatch/plans/'.$id.'/confirm')->assertConflict();
        $this->assertDatabaseCount('trips',0);
    }
    public function test_stage_threshold_can_require_approval_above_default(): void
    {
        DB::table('tenants')->where('id',$this->tenant)->update(['stage_margin_bps'=>json_encode(['FIRST_MILE'=>2500])]);
        $data=$this->planData($this->order());$data['reason_code']='BACKHAUL';
        $this->plan($data)->assertCreated()->assertJsonPath('data.required_level',1);
    }
    public function test_maintenance_change_after_plan_blocks_dispatch(): void
    {
        $data=$this->planData($this->order());$id=$this->plan($data)->assertCreated()->json('data.id');
        DB::table('vehicles')->where('id',$data['vehicle_id'])->update(['status'=>'MAINTENANCE']);
        $this->postJson('/api/v1/dispatch/plans/'.$id.'/confirm')->assertUnprocessable();$this->assertDatabaseCount('trips',0);
    }
    public function test_stale_readiness_and_expired_driver_eligibility_block_dispatch(): void
    {
        $data=$this->planData($this->order());
        DB::table('vehicles')->where('id',$data['vehicle_id'])->update(['readiness_checked_at'=>now()->subHour()]);
        $this->plan($data)->assertUnprocessable();
        DB::table('vehicles')->where('id',$data['vehicle_id'])->update(['readiness_checked_at'=>now()]);
        DB::table('drivers')->where('id',$data['driver_id'])->update(['eligibility_verified_until'=>now()]);
        $this->plan($data)->assertUnprocessable();
    }
    public function test_hazmat_and_overweight_cargo_fail_closed(): void
    {
        $order=$this->order();$data=$this->planData($order);
        DB::table('order_items')->where('order_id',$order)->update(['hazmat_class'=>'3']);
        $this->plan($data)->assertUnprocessable();
        DB::table('order_items')->where('order_id',$order)->update(['hazmat_class'=>null,'unit_weight_g'=>10000000]);
        $this->plan($data)->assertUnprocessable();
    }
    public function test_duplicate_asset_allocation_is_blocked(): void
    {
        $data=$this->planData($this->order());$first=$this->plan($data)->assertCreated()->json('data.id');
        $data['order_id']=$this->order();$second=$this->plan($data)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/dispatch/plans/'.$first.'/confirm')->assertOk();
        $this->postJson('/api/v1/dispatch/plans/'.$second.'/confirm')->assertConflict();$this->assertDatabaseCount('trips',1);
    }
    public function test_draft_and_cancelled_orders_cannot_dispatch(): void
    {
        $order=$this->order(false);$data=$this->planData($order);
        $this->plan($data)->assertUnprocessable();
        $this->postJson('/api/v1/orders/'.$order.'/transition',['action'=>'cancel'])->assertOk();
        $this->postJson('/api/v1/orders/'.$order.'/transition',['action'=>'confirm'])->assertConflict();
    }
    public function test_unsupported_identity_mode_does_not_fall_back_to_local(): void
    {
        config(['optideli.identity_mode'=>'optinexus']);
        $this->getJson('/api/v1/orders')->assertStatus(503);
    }
}
