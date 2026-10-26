<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Planning\ReservationService;
use App\Domain\Planning\RouteTemplateService;
use App\Domain\Planning\ScheduleService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Route templates, vehicle reservations, and the schedule and monitoring read models. Reading needs the internal access every
 * operations screen needs; templates and reservations are changed with spm.manage.
 */
class ScheduleController extends Controller
{
    private function templates(Request $r, bool $write = false): RouteTemplateService
    {
        if ($write) {
            Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengubah template rute.');
        }

        return new RouteTemplateService(OrderController::storeFor($r));
    }

    private function reservations(Request $r, bool $write = false): ReservationService
    {
        if ($write) {
            Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh membooking kendaraan.');
        }

        return new ReservationService(OrderController::storeFor($r));
    }

    public function templateIndex(Request $r)
    {
        return response()->json(['data' => $this->templates($r)->list($r->query('q'), $r->query('active'))]);
    }

    public function templateShow(Request $r, string $id)
    {
        return response()->json(['data' => $this->templates($r)->show($id)]);
    }

    public function templateStore(Request $r)
    {
        return response()->json(['data' => $this->templates($r, true)->create($r->all())], 201);
    }

    public function templateUpdate(Request $r, string $id)
    {
        return response()->json(['data' => $this->templates($r, true)->update($id, $r->all())]);
    }

    public function templateStatus(Request $r, string $id)
    {
        $d = $r->validate(['active' => 'required|boolean']);

        return response()->json(['data' => $this->templates($r, true)->setActive($id, (bool) $d['active'])]);
    }

    public function templateFromTrip(Request $r, string $id)
    {
        return response()->json(['data' => $this->templates($r, true)->fromTrip($id, $r->all())], 201);
    }

    public function reservationIndex(Request $r)
    {
        return response()->json(['data' => $this->reservations($r)->list($r->query('from'), $r->query('to'), $r->query('status'))]);
    }

    public function reservationStore(Request $r)
    {
        return response()->json(['data' => $this->reservations($r, true)->create($r->all())], 201);
    }

    public function reservationCancel(Request $r, string $id)
    {
        return response()->json(['data' => $this->reservations($r, true)->cancel($id, (string) $r->input('reason'))]);
    }

    public function schedule(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after:from']);
        $from = CarbonImmutable::parse($d['from'] ?? now()->startOfDay());
        $to = CarbonImmutable::parse($d['to'] ?? $from->addDays(7));
        abort_if($from->diffInDays($to) > 62, 422, 'Rentang jadwal maksimal 62 hari.');

        return response()->json(['data' => (new ScheduleService(OrderController::storeFor($r)))->schedule($from, $to)]);
    }

    public function monitoring(Request $r)
    {
        return response()->json(['data' => (new ScheduleService(OrderController::storeFor($r)))->monitoring()]);
    }
}
