<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Read-only view of one customer's own shipments and invoices. Nothing here exposes buying prices, vendors, drivers or margins. */
class CustomerPortalController extends Controller
{
    /** Provisioned by staff from the customer record. */
    public function account(Request $r, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $d, $id) {
            $customer = $s->find('customers', $id);
            abort_unless($customer->active, 422, 'Pelanggan tidak aktif.');
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            DB::table('memberships')->insert(['tenant_id' => $s->tenantId, 'user_id' => $u->id, 'role' => 'customer', 'customer_id' => $id, 'active' => true]);
            $s->event('customer.account_created', $id, ['user_id' => $u->id]);
        });

        return response()->json(['data' => ['status' => 'created']], 201);
    }

    public function portal(Request $r)
    {
        $s = OrderController::storeFor($r);
        $customerId = $r->attributes->get('customer_id');
        $customer = $s->find('customers', $customerId);
        $open = $s->query('transport_orders')->where('customer_id', $customerId)->whereNotIn('status', ['DRAFT', 'COMPLETED', 'CANCELLED'])->count();
        $unpaid = $s->query('ledger_documents')->where('customer_id', $customerId)->where('kind', 'CUSTOMER_INVOICE')->whereIn('status', ['OPEN', 'PARTIAL'])->get(['amount_minor', 'paid_minor']);

        return response()->json(['data' => [
            'customer' => ['code' => $customer->code, 'name' => $customer->name],
            'open_spm' => $open,
            'unpaid_invoices' => $unpaid->count(),
            'outstanding_minor' => (int) $unpaid->sum(fn ($i) => $i->amount_minor - $i->paid_minor),
        ]]);
    }

    public function spms(Request $r)
    {
        $s = OrderController::storeFor($r);
        $q = $s->query('transport_orders')->where('customer_id', $r->attributes->get('customer_id'))->where('status', '!=', 'DRAFT');
        if ($search = trim((string) $r->query('search', ''))) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('reference', 'ilike', $like)
                ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('awbs')->whereColumn('awbs.transport_order_id', 'transport_orders.id')->where('awbs.number', 'ilike', $like)));
        }
        if ($status = $r->query('status')) {
            $q->where('status', $status);
        }
        $rows = $q->orderByDesc('created_at')->limit(200)->get(['id', 'number', 'status', 'origin_text', 'destination_text', 'reference', 'planned_pickup_at', 'planned_delivery_at']);
        $awbs = $s->query('awbs')->whereIn('transport_order_id', $rows->pluck('id'))->get(['transport_order_id', 'number', 'status'])->groupBy('transport_order_id');
        foreach ($rows as $row) {
            $row->awbs = ($awbs[$row->id] ?? collect())->map(fn ($a) => ['number' => $a->number, 'status' => $a->status])->values();
        }

        return response()->json(['data' => $rows]);
    }

    public function spm(Request $r, string $id)
    {
        $s = OrderController::storeFor($r);
        $spm = $s->query('transport_orders')->where('id', $id)->where('customer_id', $r->attributes->get('customer_id'))->where('status', '!=', 'DRAFT')->first();
        abort_unless($spm, 404);
        $awbs = $s->query('awbs')->where('transport_order_id', $id)->orderBy('sequence')->get(['id', 'number', 'status', 'destination_text', 'consignee_name', 'delivered_at']);
        $lines = $s->query('shipment_lines')->where('transport_order_id', $id)->orderBy('line_no')->get(['awb_id', 'po_number', 'so_number', 'material', 'quantity', 'weight_kg', 'damage_qty', 'loss_qty', 'over_qty']);
        $history = $s->query('spm_status_history')->where('transport_order_id', $id)->orderBy('created_at')->get(['to_status', 'created_at']);
        $charges = $s->query('spm_charges')->where('transport_order_id', $id)->where('side', 'SELL')->where('status', 'VERIFIED')->get();
        $codes = $s->query('charge_codes')->whereIn('id', $charges->pluck('charge_code_id'))->pluck('name', 'id');

        return response()->json(['data' => [
            'spm' => collect($spm)->only(['id', 'number', 'status', 'origin_text', 'destination_text', 'reference', 'planned_pickup_at', 'planned_delivery_at']),
            'awbs' => $awbs->map(fn ($a) => [...(array) $a, 'lines' => $lines->where('awb_id', $a->id)->values()->map(fn ($l) => collect($l)->except('awb_id'))])->values(),
            'history' => $history,
            'charges' => $charges->map(fn ($c) => ['name' => $codes[$c->charge_code_id] ?? null, 'amount_minor' => (int) $c->amount_minor])->values(),
        ]]);
    }

    public function invoices(Request $r)
    {
        $s = OrderController::storeFor($r);
        $rows = $s->query('ledger_documents')->where('customer_id', $r->attributes->get('customer_id'))->where('kind', 'CUSTOMER_INVOICE')->where('status', '!=', 'VOID')
            ->orderByDesc('issue_date')->orderByDesc('number')->limit(200)->get(['id', 'number', 'issue_date', 'due_date', 'status', 'amount_minor', 'paid_minor']);

        return response()->json(['data' => $rows]);
    }

    public function invoice(Request $r, string $id)
    {
        $s = OrderController::storeFor($r);
        $doc = $s->query('ledger_documents')->where('id', $id)->where('customer_id', $r->attributes->get('customer_id'))->where('kind', 'CUSTOMER_INVOICE')->where('status', '!=', 'VOID')->first();
        abort_unless($doc, 404);
        $lines = $s->query('ledger_document_lines')->where('document_id', $id)->where('active', true)->where('side', 'SELL')->get(['description', 'quantity', 'unit_price_minor', 'amount_minor', 'transport_order_id']);
        $spms = $s->query('transport_orders')->whereIn('id', $lines->pluck('transport_order_id')->filter())->pluck('number', 'id');
        foreach ($lines as $l) {
            $l->spm_number = $l->transport_order_id ? ($spms[$l->transport_order_id] ?? null) : null;
            unset($l->transport_order_id);
        }

        return response()->json(['data' => [
            'invoice' => collect($doc)->only(['id', 'number', 'issue_date', 'due_date', 'status', 'amount_minor', 'paid_minor', 'subtotal_minor', 'tax_minor']),
            'lines' => $lines,
        ]]);
    }
}
