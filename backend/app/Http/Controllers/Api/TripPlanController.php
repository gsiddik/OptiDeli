<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Planning\TripPlanner;
use App\Domain\Spm\AwbService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * AWBs of an SPM and the trips that carry them. Reading needs the internal access every operations screen needs; changing needs
 * spm.manage; approving a trip below the margin threshold needs the dispatch approval level the margin requires; the money of a
 * trip needs rate.buy.view.
 */
class TripPlanController extends Controller
{
    private function planner(Request $r): TripPlanner
    {
        return new TripPlanner(OrderController::storeFor($r), app(FleetAvailability::class));
    }

    private function manager(Request $r): TripPlanner
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh merencanakan perjalanan.');

        return $this->planner($r);
    }

    private function awbs(Request $r): AwbService
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengubah AWB.');

        return new AwbService(OrderController::storeFor($r));
    }

    /** Hides the money of a trip from those who may not see buying prices. */
    private function view(Request $r, array $data): array
    {
        if (! Access::can($r, 'rate.buy.view')) {
            $data['trip']->cost_minor = null;
            $data['trip']->sell_minor = null;
        }

        return $data;
    }

    public function index(Request $r)
    {
        return response()->json($this->planner($r)->list($r->query('status'), $r->query('q'), $r->query('spm_id'), max(1, (int) $r->query('page', 1))));
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->planner($r)->show($id))]);
    }

    public function store(Request $r)
    {
        $result = $this->manager($r)->create($r->all());

        return response()->json(['data' => ['warnings' => $result['warnings']] + $this->view($r, $result['trip'])], 201);
    }

    public function reorder(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->manager($r)->reorder($id, (array) $r->input('stop_ids', [])))]);
    }

    public function approve(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->planner($r)->approve($id, Access::approvalLevel($r)))]);
    }

    public function start(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->manager($r)->start($id))]);
    }

    public function complete(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->manager($r)->complete($id))]);
    }

    public function cancel(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r, $this->manager($r)->cancel($id, (string) $r->input('reason')))]);
    }

    public function stop(Request $r, string $id, string $stop, string $event)
    {
        $result = $this->manager($r)->recordStop($id, $stop, $event, $r->input('at'));

        return response()->json(['data' => ['warnings' => $result['warnings']] + $this->view($r, $result['trip'])]);
    }

    public function createAwb(Request $r, string $spm)
    {
        return response()->json(['data' => $this->awbs($r)->create($spm, $r->all())], 201);
    }

    public function updateAwb(Request $r, string $id)
    {
        return response()->json(['data' => $this->awbs($r)->update($id, $r->all())]);
    }

    public function cancelAwb(Request $r, string $id)
    {
        return response()->json(['data' => $this->awbs($r)->cancel($id, (string) $r->input('reason'))]);
    }
}
