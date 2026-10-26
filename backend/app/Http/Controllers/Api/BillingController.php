<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\ReportService;
use App\Domain\Billing\VendorBillService;
use App\Domain\Billing\VerificationService;
use App\Domain\Ledger\LedgerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Verification of SPM charges, billing to the customer, the vendor bill per SPM and the management reports. Each step has its own
 * permission: selling.verify and cost.verify decide charges, billing.issue bills, report.pnl.view reads the contribution P&L.
 */
class BillingController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    private function billing(Request $r, string $permission = 'billing.issue'): BillingService
    {
        Access::authorize($r, $permission, 'Akun Anda tidak boleh mengelola penagihan.');

        return new BillingService(OrderController::storeFor($r), $this->ledger);
    }

    public function verify(Request $r, string $id)
    {
        $d = $r->validate(['side' => 'required|in:SELL,BUY', 'decision' => 'required|in:VERIFY,REJECT', 'charge_ids' => 'nullable|array|max:200', 'charge_ids.*' => 'uuid', 'note' => 'nullable|string|max:500']);
        Access::authorize($r, $d['side'] === 'SELL' ? 'selling.verify' : 'cost.verify', $d['side'] === 'SELL' ? 'Akun Anda tidak boleh memverifikasi biaya jual.' : 'Akun Anda tidak boleh memverifikasi biaya beli.');

        return response()->json(['data' => (new VerificationService(OrderController::storeFor($r)))->decide($id, $d['side'], $d['decision'], $d['charge_ids'] ?? null, $d['note'] ?? null)]);
    }

    public function ready(Request $r)
    {
        return response()->json(['data' => $this->billing($r)->ready($r->query('customer_id'), $r->query('project_id'))]);
    }

    public function run(Request $r)
    {
        return response()->json(['data' => $this->billing($r)->run($r->all())], 201);
    }

    public function batches(Request $r)
    {
        return response()->json($this->billing($r)->batches(max(1, (int) $r->query('page', 1))));
    }

    public function batch(Request $r, string $id)
    {
        return response()->json(['data' => $this->billing($r)->batch($id)]);
    }

    public function cancelBatch(Request $r, string $id)
    {
        return response()->json(['data' => $this->billing($r)->cancelBatch($id, (string) $r->input('reason'))]);
    }

    public function invoice(Request $r, string $id)
    {
        abort_unless(Access::can($r, 'billing.issue') || Access::can($r, 'finance.access'), 403, 'Akun Anda tidak boleh melihat invoice.');

        return response()->json(['data' => (new BillingService(OrderController::storeFor($r), $this->ledger))->invoice($id)]);
    }

    public function settings(Request $r)
    {
        return response()->json(['data' => $this->billing($r)->settings()]);
    }

    public function updateSettings(Request $r)
    {
        Access::authorize($r, 'policy.manage', 'Hanya pemegang kebijakan yang boleh mengubah tarif pajak.');

        return response()->json(['data' => (new BillingService(OrderController::storeFor($r), $this->ledger))->setTaxRate((int) $r->input('tax_rate_bps'))]);
    }

    private function vendorBills(Request $r): VendorBillService
    {
        Access::authorize($r, 'cost.verify', 'Akun Anda tidak boleh mencatat tagihan vendor.');

        return new VendorBillService(OrderController::storeFor($r), $this->ledger);
    }

    public function vendorPreview(Request $r, string $id)
    {
        return response()->json(['data' => $this->vendorBills($r)->preview($id)]);
    }

    public function vendorBill(Request $r, string $id)
    {
        return response()->json(['data' => $this->vendorBills($r)->record($id, $r->all())], 201);
    }

    // ---------------------------------------------------------------- reports

    private function reports(Request $r): ReportService
    {
        return new ReportService(OrderController::storeFor($r));
    }

    public function reportCatalog(Request $r)
    {
        return response()->json(['data' => array_values(array_filter($this->reports($r)->catalog(), fn ($c) => Access::can($r, $c['permission'])))]);
    }

    public function pnl(Request $r)
    {
        Access::authorize($r, 'report.pnl.view', 'Akun Anda tidak boleh melihat laporan P&L.');
        $d = $r->validate(['group_by' => 'required|in:spm,customer,project,vehicle,vendor,lane', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:json,csv,xlsx']);
        $report = $this->reports($r)->pnl($d['group_by'], $d['from'] ?? null, $d['to'] ?? null);
        if (in_array($d['format'] ?? 'json', ['csv', 'xlsx'], true)) {
            $file = $this->reports($r)->exportPnl($report, $d['format']);

            return response()->download($file[0], $file[1])->deleteFileAfterSend(true);
        }

        return response()->json(['data' => $report]);
    }

    public function arAging(Request $r)
    {
        Access::authorize($r, 'finance.access', 'Akun Anda tidak boleh melihat umur piutang.');

        return response()->json(['data' => $this->reports($r)->arAging()]);
    }

    public function spmStatus(Request $r)
    {
        return response()->json(['data' => $this->reports($r)->spmStatus()]);
    }
}
