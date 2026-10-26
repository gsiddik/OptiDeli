<?php

namespace App\Domain\Customer;

use Illuminate\Support\Facades\DB;

/**
 * What one customer may see of its own shipments and invoices. The portal (a signed-in customer user) and the customer API (a scoped
 * token) read through the same class so they can never disagree about what is visible. No buying prices, vendors, drivers or margins.
 */
final class CustomerReader
{
    public function __construct(private readonly string $tenantId, private readonly string $customerId) {}

    private function query(string $table)
    {
        return DB::table($table)->where('tenant_id', $this->tenantId);
    }

    public function summary(): array
    {
        $customer = $this->query('customers')->where('id', $this->customerId)->firstOrFail();
        $open = $this->query('transport_orders')->where('customer_id', $this->customerId)->whereNotIn('status', ['DRAFT', 'COMPLETED', 'CANCELLED'])->count();
        $unpaid = $this->query('ledger_documents')->where('customer_id', $this->customerId)->where('kind', 'CUSTOMER_INVOICE')->whereIn('status', ['OPEN', 'PARTIAL'])->get(['amount_minor', 'paid_minor']);

        return [
            'customer' => ['code' => $customer->code, 'name' => $customer->name],
            'open_spm' => $open,
            'unpaid_invoices' => $unpaid->count(),
            'outstanding_minor' => (int) $unpaid->sum(fn ($i) => $i->amount_minor - $i->paid_minor),
        ];
    }

    public function spms(?string $search = null, ?string $status = null)
    {
        $q = $this->query('transport_orders')->where('customer_id', $this->customerId)->where('status', '!=', 'DRAFT');
        if ($search = trim((string) $search)) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('reference', 'ilike', $like)
                ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('awbs')->whereColumn('awbs.transport_order_id', 'transport_orders.id')->where('awbs.number', 'ilike', $like)));
        }
        if ($status) {
            $q->where('status', $status);
        }
        $rows = $q->orderByDesc('created_at')->limit(200)->get(['id', 'number', 'status', 'origin_text', 'destination_text', 'reference', 'planned_pickup_at', 'planned_delivery_at']);
        $awbs = $this->query('awbs')->whereIn('transport_order_id', $rows->pluck('id'))->get(['transport_order_id', 'number', 'status'])->groupBy('transport_order_id');
        foreach ($rows as $row) {
            $row->awbs = ($awbs[$row->id] ?? collect())->map(fn ($a) => ['number' => $a->number, 'status' => $a->status])->values();
        }

        return $rows;
    }

    public function spm(string $id): ?array
    {
        $spm = $this->query('transport_orders')->where('id', $id)->where('customer_id', $this->customerId)->where('status', '!=', 'DRAFT')->first();
        if (! $spm) {
            return null;
        }
        $awbs = $this->query('awbs')->where('transport_order_id', $id)->orderBy('sequence')->get(['id', 'number', 'status', 'destination_text', 'consignee_name', 'delivered_at']);
        $lines = $this->query('shipment_lines')->where('transport_order_id', $id)->orderBy('line_no')->get(['awb_id', 'po_number', 'so_number', 'material', 'quantity', 'weight_kg', 'damage_qty', 'loss_qty', 'over_qty']);
        $history = $this->query('spm_status_history')->where('transport_order_id', $id)->orderBy('created_at')->get(['to_status', 'created_at']);
        $charges = $this->query('spm_charges')->where('transport_order_id', $id)->where('side', 'SELL')->where('status', 'VERIFIED')->get();
        $codes = $this->query('charge_codes')->whereIn('id', $charges->pluck('charge_code_id'))->pluck('name', 'id');

        return [
            'spm' => collect($spm)->only(['id', 'number', 'status', 'origin_text', 'destination_text', 'reference', 'planned_pickup_at', 'planned_delivery_at'])->all(),
            'awbs' => $awbs->map(fn ($a) => [...(array) $a, 'lines' => $lines->where('awb_id', $a->id)->values()->map(fn ($l) => collect($l)->except('awb_id')->all())->all()])->values()->all(),
            'history' => $history->all(),
            'charges' => $charges->map(fn ($c) => ['name' => $codes[$c->charge_code_id] ?? null, 'amount_minor' => (int) $c->amount_minor])->values()->all(),
        ];
    }

    public function invoices()
    {
        return $this->query('ledger_documents')->where('customer_id', $this->customerId)->where('kind', 'CUSTOMER_INVOICE')->where('status', '!=', 'VOID')
            ->orderByDesc('issue_date')->orderByDesc('number')->limit(200)->get(['id', 'number', 'issue_date', 'due_date', 'status', 'amount_minor', 'paid_minor']);
    }

    public function invoice(string $id): ?array
    {
        $doc = $this->query('ledger_documents')->where('id', $id)->where('customer_id', $this->customerId)->where('kind', 'CUSTOMER_INVOICE')->where('status', '!=', 'VOID')->first();
        if (! $doc) {
            return null;
        }
        $lines = $this->query('ledger_document_lines')->where('document_id', $id)->where('active', true)->where('side', 'SELL')->get(['description', 'quantity', 'unit_price_minor', 'amount_minor', 'transport_order_id']);
        $spms = $this->query('transport_orders')->whereIn('id', $lines->pluck('transport_order_id')->filter())->pluck('number', 'id');
        foreach ($lines as $l) {
            $l->spm_number = $l->transport_order_id ? ($spms[$l->transport_order_id] ?? null) : null;
            unset($l->transport_order_id);
        }

        return [
            'invoice' => collect($doc)->only(['id', 'number', 'issue_date', 'due_date', 'status', 'amount_minor', 'paid_minor', 'subtotal_minor', 'tax_minor'])->all(),
            'lines' => $lines->all(),
        ];
    }
}
