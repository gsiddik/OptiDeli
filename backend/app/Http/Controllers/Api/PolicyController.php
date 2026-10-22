<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyController extends Controller
{
    public function show(Request $request)
    {
        $tenant = DB::table('tenants')->where('id', $request->attributes->get('tenant')->id)->firstOrFail();

        return response()->json(['data' => ['minimum_margin_bps' => $tenant->minimum_margin_bps, 'stage_margin_bps' => json_decode($tenant->stage_margin_bps ?? '{}'), 'version' => $tenant->policy_version]]);
    }

    public function update(Request $request)
    {
        abort_unless($request->attributes->get('role') === 'cfo', 403, 'Hanya CFO / Owner yang dapat mengubah kebijakan margin.');
        $data = $request->validate(['minimum_margin_bps' => 'required|integer|min:0|max:10000', 'stage_margin_bps' => 'present|array:FIRST_MILE,MIDDLE_MILE,LAST_MILE,E2E', 'stage_margin_bps.*' => 'integer|min:0|max:10000', 'reason' => 'required|string|min:20|max:2000']);
        $s = OrderController::storeFor($request);
        DB::transaction(function () use ($s, $data) {
            $tenant = DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            DB::table('tenants')->where('id', $s->tenantId)->update(['minimum_margin_bps' => $data['minimum_margin_bps'], 'stage_margin_bps' => json_encode($data['stage_margin_bps'], JSON_THROW_ON_ERROR), 'policy_version' => $tenant->policy_version + 1, 'updated_at' => now()]);
            $s->event('commercial.policy_changed', $s->tenantId, ['before' => ['minimum_margin_bps' => $tenant->minimum_margin_bps, 'stage_margin_bps' => json_decode($tenant->stage_margin_bps ?? '{}')], 'after' => $data, 'version' => $tenant->policy_version + 1]);
        }, 3);

        return $this->show($request);
    }
}
