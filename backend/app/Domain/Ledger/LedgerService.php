<?php

namespace App\Domain\Ledger;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The OptiDeli sub-ledger (decision K2): customer invoices (receivables), vendor bills (payables) and the payments against them.
 * No accounts and no journals live here: every fact is published on the Opti Integration Feed for the accounting system.
 * Each mutation locks its document (or order / freight invoice) row and writes its feed event in the same transaction.
 */
final class LedgerService
{
    private const PREFIX = ['CUSTOMER_INVOICE' => 'AR', 'VENDOR_BILL' => 'AP'];

    public function __construct(private readonly FeedRecorder $feed) {}

    /** @return array{0: object, 1: bool} the document and whether it was created now (false = already issued) */
    public function issueCustomerInvoice(TenantStore $s, string $orderId, ?int $termsDays, ?string $dueDate): array
    {
        return DB::transaction(function () use ($s, $orderId, $termsDays, $dueDate) {
            $order = $s->find('orders', $orderId, true);
            abort_unless($order->status === 'DELIVERED', 422, 'Invoice pelanggan hanya untuk order yang sudah terkirim.');
            $live = $s->query('ledger_documents')->where('kind', 'CUSTOMER_INVOICE')->where('order_id', $orderId)->where('status', '<>', 'VOID')->first();
            if ($live) {
                return [$live, false];
            }
            abort_unless($order->sell_minor > 0, 422, 'Order tidak memiliki nilai jual.');
            $trip = $s->query('trips')->where('order_id', $orderId)->first();

            $doc = $this->insertDocument($s, 'CUSTOMER_INVOICE', [
                'order_id' => $orderId, 'trip_id' => $trip->id ?? null, 'counterparty_name' => $order->customer_name,
                'currency' => $order->currency, 'amount_minor' => (int) $order->sell_minor,
            ], $termsDays, $dueDate);
            $this->publishDocument($s, $doc, 'optideli.customer_invoice.issued', ['customer_name' => $order->customer_name, 'order_number' => $order->number]);
            $s->event('ledger.customer_invoice_issued', $doc->id, ['number' => $doc->number]);

            return [$doc, true];
        }, 3);
    }

    /** @return array{0: object, 1: bool} */
    public function recordVendorBill(TenantStore $s, string $freightInvoiceId, ?int $termsDays, ?string $dueDate): array
    {
        return DB::transaction(function () use ($s, $freightInvoiceId, $termsDays, $dueDate) {
            $invoice = $s->find('freight_invoices', $freightInvoiceId, true);
            abort_unless($invoice->status === 'READY_FOR_PAYMENT', 422, 'Hanya invoice freight yang siap dibayar yang dapat dicatat sebagai tagihan vendor.');
            $live = $s->query('ledger_documents')->where('kind', 'VENDOR_BILL')->where('freight_invoice_id', $freightInvoiceId)->where('status', '<>', 'VOID')->first();
            if ($live) {
                return [$live, false];
            }
            abort_unless($invoice->total_minor > 0, 422, 'Invoice freight bernilai nol.');
            $trip = $s->find('trips', $invoice->trip_id);
            $order = $s->find('orders', $trip->order_id);
            $vendor = $s->find('vendors', $invoice->vendor_id);

            $doc = $this->insertDocument($s, 'VENDOR_BILL', [
                'order_id' => $order->id, 'trip_id' => $trip->id, 'vendor_id' => $vendor->id, 'freight_invoice_id' => $invoice->id,
                'counterparty_name' => $vendor->name, 'currency' => $order->currency, 'amount_minor' => (int) $invoice->total_minor,
            ], $termsDays, $dueDate);
            $this->publishDocument($s, $doc, 'optideli.vendor_bill.recorded', [
                'vendor_code' => $vendor->code, 'vendor_name' => $vendor->name, 'freight_invoice_number' => $invoice->number, 'order_number' => $order->number,
            ]);
            $s->event('ledger.vendor_bill_recorded', $doc->id, ['number' => $doc->number]);

            return [$doc, true];
        }, 3);
    }

    /** @return array{0: object, 1: bool} the payment and whether it was recorded now (false = same reference replayed) */
    public function recordPayment(TenantStore $s, string $documentId, int $amountMinor, string $paidOn, string $method, string $reference): array
    {
        return DB::transaction(function () use ($s, $documentId, $amountMinor, $paidOn, $method, $reference) {
            $doc = $s->find('ledger_documents', $documentId, true);
            abort_if($doc->status === 'VOID', 422, 'Dokumen sudah dibatalkan.');
            $existing = $s->query('ledger_payments')->where('document_id', $documentId)->where('reference', $reference)->first();
            if ($existing) {
                abort_unless($existing->amount_minor === $amountMinor && $existing->paid_on === $paidOn && $existing->method === $method, 409, 'Referensi pembayaran sudah dipakai dengan data berbeda.');

                return [$existing, false];
            }
            $outstanding = $doc->amount_minor - $doc->paid_minor;
            abort_if($amountMinor > $outstanding, 422, 'Pembayaran melebihi sisa tagihan.');
            abort_if(CarbonImmutable::parse($paidOn)->isFuture(), 422, 'Tanggal pembayaran tidak boleh di masa depan.');

            $direction = $doc->kind === 'CUSTOMER_INVOICE' ? 'RECEIPT' : 'DISBURSEMENT';
            $id = (string) Str::uuid();
            DB::table('ledger_payments')->insert([
                'id' => $id, 'tenant_id' => $s->tenantId, 'document_id' => $documentId, 'direction' => $direction, 'amount_minor' => $amountMinor,
                'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference, 'recorded_by' => $s->actorId, 'created_at' => now(),
            ]);
            $paid = $doc->paid_minor + $amountMinor;
            $status = $paid === $doc->amount_minor ? 'PAID' : 'PARTIAL';
            $s->query('ledger_documents')->where('id', $documentId)->update(['paid_minor' => $paid, 'status' => $status, 'updated_at' => now()]);

            $this->feed->record($s->tenantId, 'optideli.payment.recorded', $doc->id, ['type' => 'ledger_payment', 'id' => $id, 'number' => $reference], $this->dimensions($doc), [
                'direction' => $direction, 'document_kind' => $doc->kind, 'document_number' => $doc->number, 'currency' => $doc->currency,
                'amount' => Money::decimal($amountMinor, $doc->currency), 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference,
                'outstanding_after' => Money::decimal($doc->amount_minor - $paid, $doc->currency), 'document_status' => $status,
            ]);
            $s->event('ledger.payment_recorded', $id, ['document' => $doc->number, 'direction' => $direction]);

            return [$s->find('ledger_payments', $id), true];
        }, 3);
    }

    public function void(TenantStore $s, string $documentId, string $reason): object
    {
        return DB::transaction(function () use ($s, $documentId, $reason) {
            $doc = $s->find('ledger_documents', $documentId, true);
            abort_if($doc->status === 'VOID', 409, 'Dokumen sudah dibatalkan.');
            abort_if($doc->paid_minor > 0, 422, 'Dokumen yang sudah memiliki pembayaran tidak dapat dibatalkan.');
            $s->query('ledger_documents')->where('id', $documentId)->update(['status' => 'VOID', 'void_reason' => $reason, 'updated_at' => now()]);
            $this->feed->record($s->tenantId, 'optideli.document.voided', $doc->id, ['type' => 'ledger_document', 'id' => $doc->id, 'number' => $doc->number], $this->dimensions($doc), [
                'document_kind' => $doc->kind, 'currency' => $doc->currency, 'amount' => Money::decimal($doc->amount_minor, $doc->currency), 'reason' => $reason,
            ]);
            $s->event('ledger.document_voided', $doc->id, ['number' => $doc->number]);

            return $s->find('ledger_documents', $documentId);
        }, 3);
    }

    private function insertDocument(TenantStore $s, string $kind, array $fields, ?int $termsDays, ?string $dueDate): object
    {
        DB::table('ledger_counters')->insertOrIgnore(['tenant_id' => $s->tenantId, 'kind' => $kind, 'last_number' => 0]);
        $counter = DB::table('ledger_counters')->where('tenant_id', $s->tenantId)->where('kind', $kind)->lockForUpdate()->first();
        $next = $counter->last_number + 1;
        DB::table('ledger_counters')->where('tenant_id', $s->tenantId)->where('kind', $kind)->update(['last_number' => $next]);

        $issue = CarbonImmutable::today();
        $due = $dueDate ? CarbonImmutable::parse($dueDate) : $issue->addDays($termsDays ?? 30);
        abort_if($due->lessThan($issue), 422, 'Jatuh tempo tidak boleh sebelum tanggal terbit.');
        $id = (string) Str::uuid();
        DB::table('ledger_documents')->insert([
            'id' => $id, 'tenant_id' => $s->tenantId, 'kind' => $kind, 'number' => sprintf('%s-%06d', self::PREFIX[$kind], $next),
            'issue_date' => $issue->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'OPEN', 'paid_minor' => 0,
            'created_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now(), ...$fields,
        ]);

        return $s->find('ledger_documents', $id);
    }

    private function publishDocument(TenantStore $s, object $doc, string $eventType, array $extra): void
    {
        $this->feed->record($s->tenantId, $eventType, $doc->id, ['type' => 'ledger_document', 'id' => $doc->id, 'number' => $doc->number], $this->dimensions($doc), [
            'document_kind' => $doc->kind, 'currency' => $doc->currency, 'amount' => Money::decimal($doc->amount_minor, $doc->currency),
            'issue_date' => $doc->issue_date, 'due_date' => $doc->due_date, 'counterparty_name' => $doc->counterparty_name, ...$extra,
        ]);
    }

    /** @return array<string, string|null> */
    private function dimensions(object $doc): array
    {
        return ['order_id' => $doc->order_id, 'trip_id' => $doc->trip_id, 'vendor_id' => $doc->vendor_id, 'freight_invoice_id' => $doc->freight_invoice_id];
    }
}
