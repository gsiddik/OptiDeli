<?php

namespace App\Http\Controllers\Api;

use App\Domain\Execution\Permissions;
use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sub-ledger endpoints (decision K2): receivables, payables and payments. All of them need finance.access. */
class LedgerController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $r): JsonResponse
    {
        Permissions::finance($r);
        $f = $r->validate(['kind' => ['nullable', Rule::in(['CUSTOMER_INVOICE', 'VENDOR_BILL'])], 'status' => ['nullable', Rule::in(['OPEN', 'PARTIAL', 'PAID', 'VOID'])], 'overdue' => 'nullable|boolean']);
        $q = OrderController::storeFor($r)->query('ledger_documents');
        foreach (['kind', 'status'] as $field) {
            if (! empty($f[$field])) {
                $q->where($field, $f[$field]);
            }
        }
        if (! empty($f['overdue'])) {
            $q->whereIn('status', ['OPEN', 'PARTIAL'])->where('due_date', '<', now()->toDateString());
        }

        return response()->json($q->orderByDesc('created_at')->orderBy('id')->paginate(25));
    }

    public function show(Request $r, string $id): JsonResponse
    {
        Permissions::finance($r);
        $s = OrderController::storeFor($r);
        $doc = $s->find('ledger_documents', $id);
        $doc->payments = $s->query('ledger_payments')->where('document_id', $id)->orderBy('paid_on')->orderBy('created_at')->get();

        return response()->json(['data' => $doc]);
    }

    /** Open balances per kind and currency, minor units plus a decimal string. */
    public function summary(Request $r): JsonResponse
    {
        Permissions::finance($r);
        $today = now()->toDateString();
        $rows = OrderController::storeFor($r)->query('ledger_documents')->whereIn('status', ['OPEN', 'PARTIAL'])
            ->selectRaw('kind, currency, count(*) as documents, sum(amount_minor - paid_minor) as outstanding_minor, sum(case when due_date < ? then amount_minor - paid_minor else 0 end) as overdue_minor', [$today])
            ->groupBy('kind', 'currency')->orderBy('kind')->orderBy('currency')->get()
            ->map(fn ($x) => [
                'kind' => $x->kind, 'currency' => $x->currency, 'documents' => (int) $x->documents,
                'outstanding_minor' => (int) $x->outstanding_minor, 'outstanding' => Money::decimal((int) $x->outstanding_minor, $x->currency),
                'overdue_minor' => (int) $x->overdue_minor, 'overdue' => Money::decimal((int) $x->overdue_minor, $x->currency),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function customerInvoice(Request $r): JsonResponse
    {
        Permissions::finance($r);
        $d = $r->validate(['order_id' => 'required|uuid', 'payment_terms_days' => 'nullable|integer|min:0|max:365', 'due_date' => 'nullable|date_format:Y-m-d']);
        [$doc, $created] = $this->ledger->issueCustomerInvoice(OrderController::storeFor($r), $d['order_id'], $d['payment_terms_days'] ?? null, $d['due_date'] ?? null);

        return response()->json(['data' => $doc], $created ? 201 : 200);
    }

    public function vendorBill(Request $r): JsonResponse
    {
        Permissions::finance($r);
        $d = $r->validate(['freight_invoice_id' => 'required|uuid', 'payment_terms_days' => 'nullable|integer|min:0|max:365', 'due_date' => 'nullable|date_format:Y-m-d']);
        [$doc, $created] = $this->ledger->recordVendorBill(OrderController::storeFor($r), $d['freight_invoice_id'], $d['payment_terms_days'] ?? null, $d['due_date'] ?? null);

        return response()->json(['data' => $doc], $created ? 201 : 200);
    }

    public function payment(Request $r, string $id): JsonResponse
    {
        Permissions::finance($r);
        $d = $r->validate([
            'amount_minor' => 'required|integer|min:1', 'paid_on' => 'required|date_format:Y-m-d',
            'method' => ['required', Rule::in(['BANK_TRANSFER', 'CASH', 'CHEQUE', 'OTHER'])], 'reference' => 'required|string|max:120',
        ]);
        [$payment, $created] = $this->ledger->recordPayment(OrderController::storeFor($r), $id, $d['amount_minor'], $d['paid_on'], $d['method'], $d['reference']);

        return response()->json(['data' => $payment], $created ? 201 : 200);
    }

    public function void(Request $r, string $id): JsonResponse
    {
        Permissions::finance($r);
        $d = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json(['data' => $this->ledger->void(OrderController::storeFor($r), $id, $d['reason'])]);
    }
}
