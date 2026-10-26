<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Cutover\CutoverService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Cut-over tracker per customer/project: checklist, parallel reconciliation against legacy control totals, and the gate. */
class CutoverController extends Controller
{
    private function service(Request $r): CutoverService
    {
        return new CutoverService(OrderController::storeFor($r));
    }

    private function read(Request $r): void
    {
        abort_unless(Access::can($r, 'cutover.manage') || Access::can($r, 'cutover.approve'), 403, 'Akses cut-over diperlukan.');
    }

    private function manage(Request $r): void
    {
        Access::authorize($r, 'cutover.manage', 'Akses cut-over diperlukan.');
    }

    public function index(Request $r)
    {
        $this->read($r);

        return response()->json(['data' => $this->service($r)->list(), 'checklist' => CutoverService::CHECKS]);
    }

    public function show(Request $r, string $id)
    {
        $this->read($r);

        return response()->json(['data' => $this->service($r)->show($id)]);
    }

    public function store(Request $r)
    {
        $this->manage($r);

        // A repeated request is refused by the unique code and customer/project scope.
        return response()->json(['data' => $this->service($r)->create($r->all())], 201);
    }

    public function update(Request $r, string $id)
    {
        $this->manage($r);

        return response()->json(['data' => $this->service($r)->update($id, $r->all())]);
    }

    public function check(Request $r, string $id, string $code)
    {
        $this->manage($r);

        return response()->json(['data' => $this->service($r)->setCheck($id, $code, $r->all())]);
    }

    public function saveTotals(Request $r, string $id)
    {
        $this->manage($r);

        return response()->json(['data' => $this->service($r)->saveTotals($id, $r->all())]);
    }

    public function removeTotals(Request $r, string $id, string $period)
    {
        $this->manage($r);

        return response()->json(['data' => $this->service($r)->removeTotals($id, $period)]);
    }

    public function accept(Request $r, string $id, string $period)
    {
        $this->manage($r);

        return response()->json(['data' => $this->service($r)->accept($id, $period, $r->all())]);
    }

    public function move(Request $r, string $id)
    {
        $this->manage($r);
        $d = $r->validate(['to' => 'required|in:PARALLEL,CUT_OVER', 'reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->service($r)->move($id, $d['to'], $d['reason'] ?? null, Access::can($r, 'cutover.approve'))]);
    }
}
