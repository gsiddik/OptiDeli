<?php

namespace App\Domain\Billing;

use App\Domain\Customer\Webhooks;
use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\Money;
use App\Domain\Shared\TenantStore;
use App\Domain\Spm\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing the customer from verified SPM charges. Ready to Bill = a SELL charge that is VERIFIED and on no live invoice line. A run
 * takes the ready charges of one customer (optionally one project, some SPMs, a period), groups them by the customer's invoice template
 * - one invoice per SPM, per project or per period, Plan and Unplan together or apart - and issues each group as a customer invoice
 * of the sub-ledger with its lines. Tax is computed here only when OptiDeli runs standalone; with OptiNexus the accounting system
 * derives it from the gross amounts, as the feed contract says. Cancelling a run voids its unpaid invoices and frees the charges.
 */
final class BillingService
{
    public const DEFAULT_TEMPLATE = 'STD-SPM';

    public function __construct(private readonly TenantStore $store, private readonly LedgerService $ledger) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---------------------------------------------------------------- ready to bill

    private function readyQuery()
    {
        return DB::table('spm_charges as c')->join('transport_orders as t', fn ($j) => $j->on('t.id', '=', 'c.transport_order_id')->on('t.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $this->store->tenantId)->where('c.side', 'SELL')->where('c.status', 'VERIFIED')->where('t.status', '<>', 'CANCELLED')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_document_lines as l')->whereColumn('l.charge_id', 'c.id')->where('l.active', true));
    }

    private function contractReadyQuery()
    {
        return DB::table('contract_charges as k')->join('fixcost_contracts as f', fn ($j) => $j->on('f.id', '=', 'k.contract_id')->on('f.tenant_id', '=', 'k.tenant_id'))
            ->where('k.tenant_id', $this->store->tenantId)->where('k.side', 'SELL')->where('k.status', 'VERIFIED')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_document_lines as l')->whereColumn('l.contract_charge_id', 'k.id')->where('l.active', true));
    }

    /** @return list<array<string, mixed>> the ready SPMs and contract months with their charges */
    public function ready(?string $customerId, ?string $projectId): array
    {
        $query = $this->readyQuery()->select('c.*', 't.number as spm_number', 't.customer_id', 't.project_id', 't.origin_text', 't.destination_text', 't.order_id');
        if ($customerId !== null && Str::isUuid($customerId)) {
            $query->where('t.customer_id', $customerId);
        }
        if ($projectId !== null && Str::isUuid($projectId)) {
            $query->where('t.project_id', $projectId);
        }
        $rows = $query->orderBy('t.number')->orderBy('c.created_at')->limit(2000)->get();
        $customers = $this->store->query('customers')->pluck('name', 'id');
        $projects = $this->store->query('customer_projects')->pluck('name', 'id');
        $codes = $this->store->query('charge_codes')->whereIn('id', $rows->pluck('charge_code_id'))->get(['id', 'code', 'name'])->keyBy('id');

        $items = $rows->groupBy('transport_order_id')->map(function ($group) use ($customers, $projects, $codes) {
            $first = $group->first();

            return [
                'type' => 'SPM', 'spm_id' => $first->transport_order_id, 'spm_number' => $first->spm_number, 'customer_id' => $first->customer_id, 'customer_label' => $customers[$first->customer_id] ?? null,
                'project_id' => $first->project_id, 'project_label' => $first->project_id ? ($projects[$first->project_id] ?? null) : null,
                'route' => $first->origin_text.' → '.$first->destination_text, 'total_minor' => (int) $group->sum('amount_minor'),
                'charges' => $group->map(fn ($c) => ['id' => $c->id, 'charge_code' => ($codes[$c->charge_code_id]->code ?? ''), 'name' => ($codes[$c->charge_code_id]->name ?? ''), 'kind' => $c->kind, 'amount_minor' => (int) $c->amount_minor, 'verified_at' => $c->verified_at])->values()->all(),
            ];
        })->values()->all();

        $contracts = $this->contractReadyQuery()->select('k.*', 'f.code as contract_code', 'f.customer_id', 'f.project_id', 'f.vehicle_id');
        if ($customerId !== null && Str::isUuid($customerId)) {
            $contracts->where('f.customer_id', $customerId);
        }
        if ($projectId !== null && Str::isUuid($projectId)) {
            $contracts->where('f.project_id', $projectId);
        }
        $krows = $contracts->orderBy('f.code')->orderBy('k.period')->limit(2000)->get();
        $plates = $this->store->query('vehicles')->pluck('plate', 'id');
        foreach ($krows->groupBy(fn ($k) => $k->contract_id.'|'.$k->period) as $group) {
            $first = $group->first();
            $items[] = [
                'type' => 'CONTRACT', 'spm_id' => null, 'spm_number' => "Kontrak {$first->contract_code} · {$first->period}", 'customer_id' => $first->customer_id, 'customer_label' => $customers[$first->customer_id] ?? null,
                'project_id' => $first->project_id, 'project_label' => $first->project_id ? ($projects[$first->project_id] ?? null) : null,
                'route' => 'Kendaraan '.($plates[$first->vehicle_id] ?? ''), 'total_minor' => (int) $group->sum('amount_minor'),
                'charges' => $group->map(fn ($k) => ['id' => $k->id, 'charge_code' => $k->kind === 'FIXED' ? 'FIXCOST' : 'TRIP_LEBIH', 'name' => $k->description, 'kind' => 'PLAN', 'amount_minor' => (int) $k->amount_minor, 'verified_at' => $k->verified_at])->values()->all(),
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------- settings

    public function taxRateBps(): int
    {
        return config('optideli.deployment_mode') === 'standalone' ? (int) ($this->store->query('billing_settings')->value('tax_rate_bps') ?? 1100) : 0;
    }

    public function settings(): array
    {
        return ['deployment_mode' => (string) config('optideli.deployment_mode'), 'tax_rate_bps' => (int) ($this->store->query('billing_settings')->value('tax_rate_bps') ?? 1100), 'tax_applied' => config('optideli.deployment_mode') === 'standalone'];
    }

    public function setTaxRate(int $bps): array
    {
        Validator::make(['bps' => $bps], ['bps' => 'required|integer|min:0|max:5000'], [], ['bps' => 'Tarif pajak'])->validate();
        DB::table('billing_settings')->upsert([['tenant_id' => $this->store->tenantId, 'tax_rate_bps' => $bps, 'updated_by' => $this->store->actorId, 'updated_at' => now()]], ['tenant_id'], ['tax_rate_bps', 'updated_by', 'updated_at']);
        $this->store->event('billing.tax_rate_changed', $this->store->tenantId, ['tax_rate_bps' => $bps]);

        return $this->settings();
    }

    // ---------------------------------------------------------------- a run

    private function template(?string $explicit, ?object $project, object $customer): object
    {
        $id = $explicit ?? $project?->invoice_template_id ?? $customer->invoice_template_id;
        $template = $id ? $this->store->query('invoice_templates')->where('id', $id)->first() : $this->store->query('invoice_templates')->where('code', self::DEFAULT_TEMPLATE)->first();
        if ($template === null) {
            $this->fail('template_id', 'Template invoice tidak ditemukan.');
        }
        if (! $template->active) {
            $this->fail('template_id', 'Template invoice tidak aktif.');
        }

        return $template;
    }

    /** @return array<string, mixed> the batch with its invoices */
    public function run(array $input): array
    {
        $d = Validator::make($input, [
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where('tenant_id', $this->store->tenantId)],
            'project_id' => ['nullable', 'uuid', Rule::exists('customer_projects', 'id')->where('tenant_id', $this->store->tenantId)],
            'template_id' => ['nullable', 'uuid', Rule::exists('invoice_templates', 'id')->where('tenant_id', $this->store->tenantId)],
            'spm_ids' => 'nullable|array|max:500', 'spm_ids.*' => 'uuid', 'contract_charge_ids' => 'nullable|array|max:500', 'contract_charge_ids.*' => 'uuid',
            'period_from' => 'nullable|date_format:Y-m-d', 'period_to' => 'nullable|date_format:Y-m-d|after_or_equal:period_from',
            'due_date' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:1000',
        ], [], ['customer_id' => 'Pelanggan', 'period_to' => 'Sampai'])->validate();

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $customer = $this->store->find('customers', $d['customer_id']);
            $project = ! empty($d['project_id']) ? $this->store->find('customer_projects', $d['project_id']) : null;
            if ($project !== null && $project->customer_id !== $customer->id) {
                $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
            }
            $template = $this->template($d['template_id'] ?? null, $project, $customer);

            // Without a selection everything that is ready for the customer is billed; with one, only what was chosen.
            $chosen = isset($d['spm_ids']) || isset($d['contract_charge_ids']);
            $spmCharges = collect();
            if (! $chosen || ! empty($d['spm_ids'])) {
                $query = $this->readyQuery()->where('t.customer_id', $customer->id)->select('c.*', 't.number as spm_number', 't.project_id', 't.origin_text', 't.destination_text', 't.order_id')->lockForUpdate();
                if ($project !== null) {
                    $query->where('t.project_id', $project->id);
                }
                if (! empty($d['spm_ids'])) {
                    $query->whereIn('t.id', $d['spm_ids']);
                }
                if (! empty($d['period_from'])) {
                    $query->where('c.verified_at', '>=', $d['period_from'].' 00:00:00');
                }
                if (! empty($d['period_to'])) {
                    $query->where('c.verified_at', '<=', $d['period_to'].' 23:59:59');
                }
                $spmCharges = $query->orderBy('t.number')->orderBy('c.created_at')->get();
            }
            $contractCharges = collect();
            if (! $chosen || ! empty($d['contract_charge_ids'])) {
                $query = $this->contractReadyQuery()->where('f.customer_id', $customer->id)->select('k.*', 'f.code as contract_code', 'f.project_id')->lockForUpdate();
                if ($project !== null) {
                    $query->where('f.project_id', $project->id);
                }
                if (! empty($d['contract_charge_ids'])) {
                    $query->whereIn('k.id', $d['contract_charge_ids']);
                }
                if (! empty($d['period_from'])) {
                    $query->where('k.verified_at', '>=', $d['period_from'].' 00:00:00');
                }
                if (! empty($d['period_to'])) {
                    $query->where('k.verified_at', '<=', $d['period_to'].' 23:59:59');
                }
                $contractCharges = $query->orderBy('f.code')->orderBy('k.period')->get();
            }
            if ($spmCharges->isEmpty() && $contractCharges->isEmpty()) {
                $this->fail('customer_id', 'Tidak ada biaya yang siap ditagih untuk pilihan ini.');
            }
            $legacy = $this->store->query('ledger_documents')->where('kind', 'CUSTOMER_INVOICE')->whereNull('billing_batch_id')->where('status', '<>', 'VOID')->whereIn('order_id', $spmCharges->pluck('order_id')->unique())->pluck('number');
            if ($legacy->isNotEmpty()) {
                $this->fail('spm_ids', 'Order dari SPM ini sudah ditagih lewat invoice order: '.$legacy->implode(', ').'.');
            }

            $codes = $this->store->query('charge_codes')->whereIn('id', $spmCharges->pluck('charge_code_id'))->get(['id', 'code', 'name'])->keyBy('id');
            // One shape for both sources, so grouping and issuing do not care where a line came from.
            $units = $spmCharges->map(fn ($c) => [
                'group' => $c->transport_order_id, 'project_id' => $c->project_id, 'order_id' => $c->order_id, 'spm_id' => $c->transport_order_id, 'label' => $c->spm_number, 'charge_kind' => $c->kind,
                'line' => [
                    'charge_id' => $c->id, 'contract_charge_id' => null, 'transport_order_id' => $c->transport_order_id, 'side' => 'SELL', 'charge_code' => $codes[$c->charge_code_id]->code, 'kind' => $c->kind,
                    'description' => mb_substr(($codes[$c->charge_code_id]->name).' — '.$c->spm_number.' ('.$c->origin_text.' → '.$c->destination_text.')', 0, 255),
                    'quantity' => $c->quantity, 'unit_price_minor' => $c->unit_price_minor === null ? null : (int) $c->unit_price_minor, 'amount_minor' => (int) $c->amount_minor,
                ],
            ])->concat($contractCharges->map(fn ($k) => [
                'group' => 'contract:'.$k->contract_id.'|'.$k->period, 'project_id' => $k->project_id, 'order_id' => null, 'spm_id' => null, 'label' => "Kontrak {$k->contract_code} {$k->period}", 'charge_kind' => 'PLAN',
                'line' => [
                    'charge_id' => null, 'contract_charge_id' => $k->id, 'transport_order_id' => null, 'side' => 'SELL', 'charge_code' => $k->kind === 'FIXED' ? 'FIXCOST' : 'TRIP_LEBIH', 'kind' => 'PLAN',
                    'description' => mb_substr($k->description, 0, 255), 'quantity' => $k->quantity, 'unit_price_minor' => $k->unit_price_minor === null ? null : (int) $k->unit_price_minor, 'amount_minor' => (int) $k->amount_minor,
                ],
            ]));

            $groups = $units->groupBy(function ($u) use ($template) {
                $key = match ($template->grouping) {
                    'PER_SPM' => $u['group'], 'PER_PROJECT' => $u['project_id'] ?? 'none', default => 'all',
                };

                return $key.($template->split_plan_unplan ? '|'.$u['charge_kind'] : '');
            });

            $period = now()->format('Ym');
            $batchId = (string) Str::uuid();
            $batchNumber = sprintf('BB-%s-%05d', $period, DocumentNumbers::next($this->store->tenantId, 'BILLING', $period));
            DB::table('billing_batches')->insert([
                'id' => $batchId, 'tenant_id' => $this->store->tenantId, 'number' => $batchNumber, 'customer_id' => $customer->id, 'project_id' => $project?->id, 'template_id' => $template->id,
                'period_from' => $d['period_from'] ?? null, 'period_to' => $d['period_to'] ?? null, 'status' => 'ISSUED', 'notes' => $d['notes'] ?? null,
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $taxBps = $this->taxRateBps();
            $terms = $template->due_days ?? ($project?->payment_terms_days ?? $customer->payment_terms_days);
            $orders = $this->store->query('orders')->whereIn('id', $spmCharges->pluck('order_id')->unique())->pluck('number', 'id');
            $invoices = [];
            $total = 0;
            foreach ($groups as $group) {
                $lines = $group->pluck('line')->all();
                $subtotal = array_sum(array_column($lines, 'amount_minor'));
                if ($subtotal <= 0) {
                    $this->fail('customer_id', 'Jumlah invoice harus lebih dari nol; periksa koreksi negatif pada '.$group->first()['label'].'.');
                }
                $tax = (int) round($subtotal * $taxBps / 10000);
                $first = $group->first();
                $spmIds = $group->pluck('spm_id')->filter()->unique();
                $single = $spmIds->count() === 1 && $group->pluck('spm_id')->unique()->count() === 1;
                $doc = $this->ledger->issueFromCharges($this->store, 'CUSTOMER_INVOICE', [
                    'order_id' => $single ? $first['order_id'] : null, 'counterparty_name' => $customer->name, 'currency' => 'IDR', 'amount_minor' => $subtotal + $tax,
                    'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'billing_batch_id' => $batchId, 'customer_id' => $customer->id, 'project_id' => $template->grouping === 'PER_PROJECT' || $single ? $first['project_id'] : null,
                    'template_id' => $template->id, 'transport_order_id' => $single ? $first['spm_id'] : null,
                ], $lines, $terms === null ? null : (int) $terms, $d['due_date'] ?? null, [
                    'customer_name' => $customer->name, 'order_number' => $group->pluck('order_id')->filter()->unique()->map(fn ($o) => $orders[$o] ?? '')->filter()->implode(', '),
                    'spm_numbers' => $group->filter(fn ($u) => $u['spm_id'])->pluck('label')->unique()->values()->all(), 'contracts' => $group->filter(fn ($u) => ! $u['spm_id'])->pluck('label')->unique()->values()->all(),
                    'billing_batch' => $batchNumber, 'template' => $template->code, 'subtotal' => Money::decimal($subtotal, 'IDR'), 'tax' => Money::decimal($tax, 'IDR'),
                ]);
                $invoices[] = $doc;
                $total += $subtotal + $tax;
            }
            DB::table('billing_batches')->where('tenant_id', $this->store->tenantId)->where('id', $batchId)->update(['invoice_count' => count($invoices), 'total_minor' => $total, 'updated_at' => now()]);
            foreach ($invoices as $invoice) {
                Webhooks::publish($this->store->tenantId, $customer->id, 'invoice.issued', $invoice->id, ['invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'issue_date' => (string) $invoice->issue_date, 'due_date' => (string) $invoice->due_date, 'currency' => $invoice->currency, 'amount_minor' => (int) $invoice->amount_minor]]);
            }
            $this->store->event('billing.batch_issued', $batchId, ['number' => $batchNumber, 'customer' => $customer->code, 'template' => $template->code, 'invoices' => count($invoices), 'total_minor' => $total]);

            return ['batch' => $this->store->find('billing_batches', $batchId), 'invoices' => array_map(fn ($i) => $this->store->find('ledger_documents', $i->id), $invoices)];
        }, 3);
    }

    // ---------------------------------------------------------------- reading

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function batches(int $page, int $perPage = 50): array
    {
        $query = $this->store->query('billing_batches');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();
        $customers = $this->store->query('customers')->whereIn('id', $rows->pluck('customer_id'))->pluck('name', 'id');
        $templates = $this->store->query('invoice_templates')->whereIn('id', $rows->pluck('template_id')->filter())->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->customer_label = $customers[$row->customer_id] ?? null;
            $row->template_label = $row->template_id ? ($templates[$row->template_id] ?? null) : null;
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public function batch(string $id): array
    {
        $batch = $this->store->find('billing_batches', $id);
        $invoices = $this->store->query('ledger_documents')->where('billing_batch_id', $id)->orderBy('number')->get();

        return ['batch' => $batch, 'invoices' => $invoices->all()];
    }

    /** The invoice with the lines laid out the way its template says. */
    public function invoice(string $id): array
    {
        $doc = $this->store->find('ledger_documents', $id);
        $template = $doc->template_id ? $this->store->query('invoice_templates')->where('id', $doc->template_id)->first() : null;
        $lines = $this->store->query('ledger_document_lines')->where('document_id', $id)->orderBy('created_at')->get();
        $spms = $this->store->query('transport_orders')->whereIn('id', $lines->pluck('transport_order_id'))->get(['id', 'number', 'order_id', 'origin_text', 'destination_text', 'created_at'])->keyBy('id');
        $orders = $this->store->query('orders')->whereIn('id', $spms->pluck('order_id'))->pluck('number', 'id');
        foreach ($lines as $line) {
            $spm = $spms[$line->transport_order_id] ?? null;
            $line->spm_number = $spm?->number;
            $line->order_number = $spm ? ($orders[$spm->order_id] ?? null) : null;
            $line->route = $spm ? $spm->origin_text.' → '.$spm->destination_text : null;
            $line->date = $spm ? substr((string) $spm->created_at, 0, 10) : null;
        }
        $customer = $doc->customer_id ? $this->store->query('customers')->where('id', $doc->customer_id)->first(['name', 'tax_id', 'address']) : null;
        $batch = $doc->billing_batch_id ? $this->store->query('billing_batches')->where('id', $doc->billing_batch_id)->value('number') : null;

        return [
            'document' => $doc, 'lines' => $lines->all(), 'customer' => $customer, 'batch_number' => $batch,
            'columns' => $template ? explode(',', $template->columns) : ['spm', 'route', 'description', 'amount'], 'footer' => $template?->footer, 'template' => $template?->name,
        ];
    }

    public function cancelBatch(string $id, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $batch = $this->store->find('billing_batches', $id, true);
            abort_unless($batch->status === 'ISSUED', 409, 'Penagihan ini sudah dibatalkan.');
            $documents = $this->store->query('ledger_documents')->where('billing_batch_id', $id)->where('status', '<>', 'VOID')->get();
            abort_if($documents->contains(fn ($d) => $d->paid_minor > 0), 422, 'Ada invoice yang sudah menerima pembayaran; penagihan ini tidak dapat dibatalkan.');
            foreach ($documents as $doc) {
                $this->ledger->void($this->store, $doc->id, mb_substr('Penagihan '.$batch->number.' dibatalkan: '.$reason, 0, 500));
            }
            DB::table('billing_batches')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->store->event('billing.batch_cancelled', $id, ['number' => $batch->number, 'reason' => $reason, 'invoices' => $documents->count()]);

            return $this->batch($id);
        }, 3);
    }
}
