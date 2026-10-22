<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function index(Request $request)
    {
        $s=OrderController::storeFor($request);
        return response()->json(['data'=>['vehicles'=>$s->query('vehicles')->orderBy('plate')->get(),'drivers'=>$s->query('drivers')->orderBy('name')->get(),'allocations'=>$s->query('trips')->get()]]);
    }
    public function vehicle(Request $request)
    {
        OrderController::writeAccess($request);
        $data=$request->validate(['plate'=>'required|string|max:30','type'=>'required|string|max:80','capacity_g'=>'required|integer|min:1|max:100000000','length_mm'=>'required|integer|min:1|max:20000','width_mm'=>'required|integer|min:1|max:20000','height_mm'=>'required|integer|min:1|max:20000','documents_valid_until'=>'required|date_format:Y-m-d|after_or_equal:today']);
        $s=OrderController::storeFor($request);$id=(string)Str::uuid();
        DB::transaction(function () use ($s,$id,$data) {
            abort_if($s->query('vehicles')->where('plate',$data['plate'])->exists(),422,'Nomor kendaraan sudah terdaftar.');
            DB::table('vehicles')->insert([...$data,'id'=>$id,'tenant_id'=>$s->tenantId,'status'=>'AVAILABLE','readiness_checked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $s->event('vehicle.registered',$id);
        });
        return response()->json(['data'=>$s->find('vehicles',$id)],201);
    }
    public function readiness(Request $request,string $id)
    {
        OrderController::writeAccess($request);
        $data=$request->validate(['status'=>['required',Rule::in(['AVAILABLE','MAINTENANCE','UNAVAILABLE'])],'note'=>'required|string|min:10|max:1000']);
        $s=OrderController::storeFor($request);
        DB::transaction(function () use ($s,$id,$data) {
            DB::table('tenants')->where('id',$s->tenantId)->lockForUpdate()->firstOrFail();
            $s->find('vehicles',$id,true);
            $s->query('vehicles')->where('id',$id)->update(['status'=>$data['status'],'readiness_checked_at'=>now(),'updated_at'=>now()]);
            $s->event('vehicle.readiness_checked',$id,$data);
        },3);
        return response()->json(['data'=>$s->find('vehicles',$id)]);
    }
    public function driver(Request $request)
    {
        OrderController::writeAccess($request);
        $data=$request->validate(['name'=>'required|string|max:150','license_number'=>'required|string|max:80','license_valid_until'=>'required|date_format:Y-m-d|after_or_equal:today','eligibility_verified_until'=>['required','date','after:now','regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);
        $s=OrderController::storeFor($request);$id=(string)Str::uuid();
        DB::transaction(function () use ($s,$id,$data) {
            abort_if($s->query('drivers')->where('license_number',$data['license_number'])->exists(),422,'Nomor SIM sudah terdaftar.');
            DB::table('drivers')->insert([...$data,'id'=>$id,'tenant_id'=>$s->tenantId,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            $s->event('driver.registered',$id,['eligibility_verified_until'=>$data['eligibility_verified_until']]);
        });
        return response()->json(['data'=>$s->find('drivers',$id)],201);
    }
    public function audit(Request $request)
    {
        return response()->json(OrderController::storeFor($request)->query('audit_events')->orderByDesc('created_at')->paginate(50));
    }
    public function summary(Request $request)
    {
        $s=OrderController::storeFor($request);
        return response()->json(['data'=>['orders'=>$s->query('orders')->selectRaw('status, count(*) as total')->groupBy('status')->get(),'approval_pending'=>$s->query('dispatch_plans')->where('status','PENDING_APPROVAL')->count(),'fleet_available'=>$s->query('vehicles')->where('status','AVAILABLE')->count(),'outbox_pending'=>$s->query('outbox_events')->whereNull('published_at')->count()]]);
    }
}
