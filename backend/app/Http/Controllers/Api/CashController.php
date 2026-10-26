<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Cash\CashService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Driver cash (uang jalan): vouchers with their maker-checker steps, cashboxes and balances, and the work queue of what is waiting
 * for the user. Each step has its own permission (see PermissionCatalog); the services enforce who may not act on their own request.
 */
class CashController extends Controller
{
    private function service(Request $r, ?string $permission = null, string $message = 'Akses ditolak.'): CashService
    {
        if ($permission !== null) {
            Access::authorize($r, $permission, $message);
        }

        return new CashService(OrderController::storeFor($r));
    }

    public function vouchers(Request $r)
    {
        return response()->json($this->service($r, 'cash.view')->list($r->query('status'), $r->query('q'), $r->query('trip_id'), max(1, (int) $r->query('page', 1))));
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.view')->show($id)]);
    }

    public function request(Request $r, string $trip)
    {
        return response()->json(['data' => $this->service($r, 'cash.request', 'Akun Anda tidak boleh meminta uang jalan.')->request($trip, $r->all())], 201);
    }

    public function approve(Request $r, string $id)
    {
        $approved = [];
        foreach ((array) $r->input('lines', []) as $line) {
            if (isset($line['id'], $line['approved_minor'])) {
                $approved[(string) $line['id']] = (int) $line['approved_minor'];
            }
        }

        return response()->json(['data' => $this->service($r, 'cash.approve', 'Akun Anda tidak boleh menyetujui uang jalan.')->approve($id, $approved, Access::can($r, 'cash.approve.large'))]);
    }

    public function reject(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.approve', 'Akun Anda tidak boleh menolak uang jalan.')->reject($id, (string) $r->input('reason'))]);
    }

    public function cancel(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.request', 'Akun Anda tidak boleh membatalkan uang jalan.')->cancel($id, (string) $r->input('reason'))]);
    }

    public function disburse(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.disburse', 'Akun Anda tidak boleh mencairkan uang jalan.')->disburse($id, (string) $r->input('cashbox_id'))]);
    }

    public function settle(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.settle', 'Akun Anda tidak boleh merealisasikan uang jalan.')->settle($id, (array) $r->input('lines', []), (int) $r->input('returned_minor', 0))]);
    }

    public function overview(Request $r)
    {
        $s = $this->service($r, 'cash.view');

        return response()->json(['data' => ['cashboxes' => $s->cashboxes(), 'drivers' => $s->driverBalances(), 'approval_limit_minor' => $s->limit()]]);
    }

    public function ledger(Request $r)
    {
        return response()->json($this->service($r, 'cash.view')->ledger($r->query('cashbox_id'), $r->query('driver_id'), max(1, (int) $r->query('page', 1))));
    }

    public function createCashbox(Request $r)
    {
        return response()->json(['data' => $this->service($r, 'cash.manage', 'Akun Anda tidak boleh mengelola kas.')->createCashbox($r->all())], 201);
    }

    public function cashboxStatus(Request $r, string $id)
    {
        $d = $r->validate(['active' => 'required|boolean']);

        return response()->json(['data' => $this->service($r, 'cash.manage', 'Akun Anda tidak boleh mengelola kas.')->setCashboxActive($id, (bool) $d['active'])]);
    }

    public function topUp(Request $r, string $id)
    {
        return response()->json(['data' => $this->service($r, 'cash.manage', 'Akun Anda tidak boleh mengelola kas.')->topUp($id, (int) $r->input('amount_minor'), $r->input('memo'))], 201);
    }

    public function limit(Request $r)
    {
        return response()->json(['data' => ['approval_limit_minor' => $this->service($r, 'cash.manage', 'Akun Anda tidak boleh mengubah batas persetujuan.')->setLimit((int) $r->input('approval_limit_minor'))]]);
    }

    /** What is waiting for this user, by permission: the numbers behind the work-queue badges. */
    public function workQueue(Request $r)
    {
        $s = OrderController::storeFor($r);
        $me = $s->actorId;
        $q = [];
        if (Access::can($r, 'spm.manage')) {
            $q['spm_draft'] = $s->query('transport_orders')->where('status', 'DRAFT')->count();
            $q['spm_reopened'] = $s->query('transport_orders')->where('status', 'REOPENED')->count();
            $q['awb_pending'] = $s->query('awbs')->where('status', 'PENDING')->whereIn('transport_order_id', $s->query('transport_orders')->whereIn('status', ['PLANNED', 'LOADING', 'IN_TRANSIT'])->select('id'))->count();
        }
        if (($level = Access::approvalLevel($r)) > 0) {
            $q['trips_to_approve'] = $s->query('spm_trips')->where('status', 'PENDING_APPROVAL')->where('required_level', '<=', $level)->where('created_by', '!=', $me)->count();
        }
        if (Access::can($r, 'cash.approve')) {
            $q['vouchers_to_approve'] = $s->query('cash_vouchers')->where('status', 'REQUESTED')->where('requested_by', '!=', $me)->count();
        }
        if (Access::can($r, 'cash.disburse')) {
            $q['vouchers_to_disburse'] = $s->query('cash_vouchers')->where('status', 'APPROVED')->where('requested_by', '!=', $me)->count();
        }
        if (Access::can($r, 'cash.settle')) {
            $q['vouchers_to_settle'] = $s->query('cash_vouchers')->where('status', 'DISBURSED')->count();
        }
        if (Access::can($r, 'rate.approve')) {
            $q['tariffs_to_approve'] = $s->query('rate_tables')->where('status', 'DRAFT')->where('created_by', '!=', $me)->whereExists(fn ($x) => $x->selectRaw('1')->from('rate_lines')->whereColumn('rate_lines.rate_table_id', 'rate_tables.id'))->count();
        }

        return response()->json(['data' => $q]);
    }
}
