<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Spm\ReopenService;
use App\Domain\Spm\SpmService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * SPM (Surat Perintah Muat / transport order). Reading needs the internal access every operations screen needs (and buying
 * prices need rate.buy.view); changing needs spm.manage, verifying documents execution.verify, re-opening or changing the
 * destination spm.reopen.
 */
class SpmController extends Controller
{
    private function service(Request $r): SpmService
    {
        return new SpmService(OrderController::storeFor($r));
    }

    private function manager(Request $r): SpmService
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengubah SPM.');

        return $this->service($r);
    }

    public function index(Request $r)
    {
        return response()->json($this->service($r)->list($r->query('status'), $r->query('q'), $r->query('order_id'), max(1, (int) $r->query('page', 1))));
    }

    public function show(Request $r, string $id)
    {
        $data = $this->service($r)->show($id, Access::can($r, 'rate.buy.view'));
        $data['reopen_request'] = $this->reopen($r)->pendingFor($id);

        return response()->json(['data' => $data]);
    }

    public function store(Request $r, string $orderId)
    {
        return response()->json(['data' => $this->manager($r)->create($orderId, $r->all())], 201);
    }

    public function update(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->update($id, $r->all())]);
    }

    public function addLine(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->addLine($id, $r->all())], 201);
    }

    public function updateLine(Request $r, string $id, string $line)
    {
        return response()->json(['data' => $this->manager($r)->updateLine($id, $line, $r->all())]);
    }

    public function deleteLine(Request $r, string $id, string $line)
    {
        $this->manager($r)->deleteLine($id, $line);

        return response()->noContent();
    }

    public function transition(Request $r, string $id)
    {
        $d = $r->validate(['to' => 'required|in:PLANNED,LOADING,IN_TRANSIT,DISCHARGED,DOC_VERIFIED,CLOSED,REOPENED,CANCELLED', 'reason' => 'nullable|string|max:500']);
        match ($d['to']) {
            'REOPENED' => Access::authorize($r, 'spm.reopen', 'Akun Anda tidak boleh membuka kembali SPM.'),
            'DOC_VERIFIED' => Access::authorize($r, 'execution.verify', 'Akun Anda tidak boleh memverifikasi dokumen.'),
            default => Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengubah SPM.'),
        };

        return response()->json(['data' => $this->service($r)->transition($id, $d['to'], $d['reason'] ?? null)]);
    }

    public function destination(Request $r, string $id)
    {
        Access::authorize($r, 'spm.reopen', 'Akun Anda tidak boleh mengganti tujuan SPM.');

        return response()->json(['data' => $this->service($r)->changeDestination($id, (string) $r->input('destination_location_id'), (string) $r->input('reason'))]);
    }

    private function reopen(Request $r): ReopenService
    {
        $store = OrderController::storeFor($r);

        return new ReopenService($store, new SpmService($store));
    }

    public function reopenQueue(Request $r)
    {
        return response()->json($this->reopen($r)->list($r->query('status'), max(1, (int) $r->query('page', 1))));
    }

    public function requestReopen(Request $r, string $id)
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengajukan buka kembali SPM.');

        return response()->json(['data' => $this->reopen($r)->request($id, (string) $r->input('reason'))], 201);
    }

    public function decideReopen(Request $r, string $id, string $decision)
    {
        Access::authorize($r, 'spm.reopen', 'Akun Anda tidak boleh memutuskan permintaan buka kembali.');

        return response()->json(['data' => $this->reopen($r)->decide($id, $decision === 'approve', $r->input('note'))]);
    }

    public function cancelReopen(Request $r, string $id)
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh menarik permintaan buka kembali.');

        return response()->json(['data' => $this->reopen($r)->cancel($id)]);
    }

    public function addCharge(Request $r, string $id)
    {
        if ($r->input('side') === 'BUY') {
            Access::authorize($r, 'rate.buy.view', 'Akun Anda tidak boleh mencatat biaya beli.');
        }

        return response()->json(['data' => $this->manager($r)->addCharge($id, $r->all())], 201);
    }
}
