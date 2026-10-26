<?php

namespace App\Domain\Billing;

use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\Money;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The vendor's bill for an SPM, recorded in the sub-ledger only when three things agree (the 3-way match): what was agreed (the buying
 * charges of the SPM, all VERIFIED), what was received (every AWB of the SPM delivered) and what the vendor invoices (the amount on the
 * vendor's invoice, to the rupiah). A mismatch records nothing and says by how much it differs: the fix is a verified correction or
 * a corrected invoice. One live bill per vendor invoice number, and a charge sits on one live bill at a time.
 */
final class VendorBillService
{
    public function __construct(private readonly TenantStore $store, private readonly LedgerService $ledger) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** What the match looks like now, without recording anything. @return array<string, mixed> */
    public function preview(string $spmId): array
    {
        $spm = $this->store->find('transport_orders', $spmId);
        $charges = $this->store->query('spm_charges')->where('transport_order_id', $spmId)->where('side', 'BUY')->where('status', '<>', 'REJECTED')->get();
        $billed = $this->store->query('ledger_document_lines')->whereIn('charge_id', $charges->pluck('id'))->where('active', true)->pluck('charge_id')->all();
        $open = $charges->reject(fn ($c) => in_array($c->id, $billed, true));
        $awbs = $this->store->query('awbs')->where('transport_order_id', $spmId)->where('status', '<>', 'CANCELLED')->get();

        return [
            'spm' => ['id' => $spm->id, 'number' => $spm->number, 'vendor_id' => $spm->vendor_id],
            'agreed_minor' => (int) $open->where('status', 'VERIFIED')->sum('amount_minor'), 'unverified_count' => $open->where('status', 'PROPOSED')->count(),
            'awb_total' => $awbs->count(), 'awb_delivered' => $awbs->where('status', 'DELIVERED')->count(), 'already_billed' => count($billed),
            'ready' => $spm->vendor_id !== null && $open->isNotEmpty() && $open->where('status', 'PROPOSED')->isEmpty() && $awbs->isNotEmpty() && $awbs->where('status', '<>', 'DELIVERED')->isEmpty(),
        ];
    }

    /** @return array<string, mixed> the bill */
    public function record(string $spmId, array $input): array
    {
        $d = Validator::make($input, [
            'vendor_invoice_number' => 'required|string|max:120', 'amount_minor' => 'required|integer|min:1|max:100000000000000', 'due_date' => 'nullable|date_format:Y-m-d',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
        ], [], ['vendor_invoice_number' => 'Nomor invoice vendor', 'amount_minor' => 'Jumlah invoice'])->validate();

        return DB::transaction(function () use ($spmId, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->store->find('transport_orders', $spmId, true);
            if (! $spm->vendor_id) {
                $this->fail('spm', 'SPM ini memakai armada sendiri; tidak ada tagihan vendor.');
            }
            $vendor = $this->store->find('vendors', $spm->vendor_id);
            $charges = $this->store->query('spm_charges')->where('transport_order_id', $spmId)->where('side', 'BUY')->where('status', '<>', 'REJECTED')->lockForUpdate()->get();
            $billed = $this->store->query('ledger_document_lines')->whereIn('charge_id', $charges->pluck('id'))->where('active', true)->pluck('charge_id')->all();
            $open = $charges->reject(fn ($c) => in_array($c->id, $billed, true))->values();
            if ($open->isEmpty()) {
                $this->fail('spm', 'Tidak ada biaya beli yang belum ditagihkan vendor pada SPM ini.');
            }
            if ($open->where('status', 'PROPOSED')->isNotEmpty()) {
                $this->fail('spm', 'Masih ada biaya beli yang belum diverifikasi (3-way match: tarif/biaya yang disepakati).');
            }
            $awbs = $this->store->query('awbs')->where('transport_order_id', $spmId)->where('status', '<>', 'CANCELLED')->get();
            if ($awbs->isEmpty() || $awbs->where('status', '<>', 'DELIVERED')->isNotEmpty()) {
                $this->fail('spm', 'Semua AWB harus sudah terkirim (3-way match: barang diterima).');
            }
            $agreed = (int) $open->sum('amount_minor');
            if ($d['amount_minor'] !== $agreed) {
                throw ValidationException::withMessages(['amount_minor' => 'Invoice vendor tidak cocok dengan biaya terverifikasi: invoice '.Money::decimal($d['amount_minor'], 'IDR').', terverifikasi '.Money::decimal($agreed, 'IDR').' (selisih '.Money::decimal($d['amount_minor'] - $agreed, 'IDR').').']);
            }
            if ($this->store->query('ledger_documents')->where('kind', 'VENDOR_BILL')->where('vendor_id', $vendor->id)->where('external_reference', $d['vendor_invoice_number'])->where('status', '<>', 'VOID')->exists()) {
                $this->fail('vendor_invoice_number', 'Nomor invoice vendor ini sudah dicatat.');
            }

            $codes = $this->store->query('charge_codes')->whereIn('id', $open->pluck('charge_code_id'))->get(['id', 'code', 'name'])->keyBy('id');
            $lines = $open->map(fn ($c) => [
                'charge_id' => $c->id, 'transport_order_id' => $spmId, 'side' => 'BUY', 'charge_code' => $codes[$c->charge_code_id]->code, 'kind' => $c->kind,
                'description' => mb_substr($codes[$c->charge_code_id]->name.' — '.$spm->number, 0, 255), 'quantity' => $c->quantity, 'unit_price_minor' => $c->unit_price_minor === null ? null : (int) $c->unit_price_minor, 'amount_minor' => (int) $c->amount_minor,
            ])->all();
            $order = $this->store->find('orders', $spm->order_id);
            $doc = $this->ledger->issueFromCharges($this->store, 'VENDOR_BILL', [
                'order_id' => $spm->order_id, 'vendor_id' => $vendor->id, 'counterparty_name' => $vendor->name, 'currency' => 'IDR', 'amount_minor' => $agreed, 'subtotal_minor' => $agreed,
                'transport_order_id' => $spmId, 'external_reference' => $d['vendor_invoice_number'],
            ], $lines, $d['payment_terms_days'] ?? null, $d['due_date'] ?? null, [
                'vendor_code' => $vendor->code, 'vendor_name' => $vendor->name, 'freight_invoice_number' => $d['vendor_invoice_number'], 'order_number' => $order->number, 'spm_number' => $spm->number,
            ]);
            $this->store->event('billing.vendor_bill_matched', $doc->id, ['spm' => $spm->number, 'vendor' => $vendor->code, 'invoice' => $d['vendor_invoice_number'], 'amount_minor' => $agreed]);

            return ['document' => $this->store->find('ledger_documents', $doc->id)];
        }, 3);
    }
}
