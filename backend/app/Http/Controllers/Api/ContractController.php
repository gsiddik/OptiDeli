<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Contract\ContractService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Fixed-cost vehicle contracts and the attendance that prorates them. Contracts carry buying prices, so they are read by those who manage
 * them or may see buying prices; verifying the charges of a month needs selling.verify (selling side) or cost.verify (buying side).
 */
class ContractController extends Controller
{
    private function service(Request $r): ContractService
    {
        return new ContractService(OrderController::storeFor($r));
    }

    private function reader(Request $r): ContractService
    {
        abort_unless(Access::can($r, 'contract.manage') || Access::can($r, 'rate.buy.view'), 403, 'Akun Anda tidak boleh melihat kontrak.');

        return $this->service($r);
    }

    private function manager(Request $r): ContractService
    {
        Access::authorize($r, 'contract.manage', 'Akun Anda tidak boleh mengelola kontrak.');

        return $this->service($r);
    }

    public function index(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->list($r->query('status'))]);
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->reader($r)->show($id)]);
    }

    public function store(Request $r)
    {
        return response()->json(['data' => $this->manager($r)->create($r->all())], 201);
    }

    public function update(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->update($id, $r->all())]);
    }

    public function end(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->end($id, (string) $r->input('reason'))]);
    }

    public function run(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->run($id, (string) $r->input('period'))], 201);
    }

    public function verify(Request $r, string $id)
    {
        $d = $r->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'side' => 'required|in:SELL,BUY', 'decision' => 'required|in:VERIFY,REJECT', 'charge_ids' => 'nullable|array|max:50', 'charge_ids.*' => 'uuid', 'note' => 'nullable|string|max:500']);
        Access::authorize($r, $d['side'] === 'SELL' ? 'selling.verify' : 'cost.verify', 'Akun Anda tidak boleh memverifikasi biaya kontrak.');

        return response()->json(['data' => $this->service($r)->verify($id, $d['period'], $d['side'], $d['decision'], $d['charge_ids'] ?? null, $d['note'] ?? null)]);
    }

    public function attend(Request $r)
    {
        Access::authorize($r, 'attendance.manage', 'Akun Anda tidak boleh mencatat kehadiran kendaraan.');

        return response()->json(['data' => ['days' => $this->service($r)->attend($r->all())]], 201);
    }

    public function attendance(Request $r, string $vehicle)
    {
        return response()->json(['data' => $this->service($r)->attendance($vehicle, (string) $r->query('period'))]);
    }
}
