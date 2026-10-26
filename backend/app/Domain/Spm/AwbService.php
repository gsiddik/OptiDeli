<?php

namespace App\Domain\Spm;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Air waybills of an SPM. An SPM carries several AWBs; each has a gap-free number, its own destination and consignee, and groups
 * some of the SPM's shipment lines. An AWB is carried by one trip (assigned when the trip is planned). Planning an SPM puts any
 * line that is not in an AWB yet into one AWB for the SPM's destination, so the simple case needs no extra step.
 */
final class AwbService
{
    private const OPEN = ['DRAFT', 'PLANNED', 'LOADING', 'IN_TRANSIT'];

    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function rules(): array
    {
        return [
            'destination_location_id' => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('tenant_id', $this->store->tenantId)->where('active', true)],
            'consignee_name' => 'nullable|string|max:200', 'consignee_phone' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:300',
        ];
    }

    private function spm(string $id): object
    {
        $spm = $this->store->find('transport_orders', $id, true);
        abort_unless(in_array($spm->status, self::OPEN, true), 409, 'AWB tidak dapat diubah pada status SPM ini.');

        return $spm;
    }

    private function nextNumber(): string
    {
        $period = now()->format('Ym');

        return sprintf('AWB-%s-%05d', $period, DocumentNumbers::next($this->store->tenantId, 'AWB', $period));
    }

    private function insert(object $spm, array $d, array $lineIds): string
    {
        $destination = $d['destination_location_id'] ?? $spm->destination_location_id;
        if (! $destination) {
            $this->fail('destination_location_id', 'Pilih lokasi tujuan AWB dari master data.');
        }
        $id = (string) Str::uuid();
        DB::table('awbs')->insert([
            'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $this->nextNumber(), 'transport_order_id' => $spm->id,
            'sequence' => (int) $this->store->query('awbs')->where('transport_order_id', $spm->id)->max('sequence') + 1, 'status' => 'PENDING',
            'destination_location_id' => $destination, 'destination_text' => mb_substr($this->store->find('locations', $destination)->name, 0, 300),
            'consignee_name' => $d['consignee_name'] ?? null, 'consignee_phone' => $d['consignee_phone'] ?? null, 'notes' => $d['notes'] ?? null,
            'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->store->query('shipment_lines')->whereIn('id', $lineIds)->update(['awb_id' => $id, 'updated_at' => now()]);

        return $id;
    }

    private function freeLines(string $spmId, array $lineIds): void
    {
        $free = $this->store->query('shipment_lines')->where('transport_order_id', $spmId)->whereIn('id', $lineIds)->whereNull('awb_id')->count();
        if ($free !== count($lineIds)) {
            $this->fail('line_ids', 'Baris muatan harus milik SPM ini dan belum berada di AWB lain.');
        }
    }

    public function create(string $spmId, array $input): object
    {
        $d = Validator::make($input, $this->rules() + ['line_ids' => 'required|array|min:1|max:500', 'line_ids.*' => 'required|uuid|distinct'], [], ['line_ids' => 'Baris muatan'])->validate();

        return DB::transaction(function () use ($spmId, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->spm($spmId);
            $this->freeLines($spmId, $d['line_ids']);
            $id = $this->insert($spm, $d, $d['line_ids']);
            $awb = $this->store->find('awbs', $id);
            $this->store->event('awb.created', $id, ['number' => $awb->number, 'spm' => $spm->number, 'lines' => count($d['line_ids'])]);

            return $awb;
        }, 3);
    }

    private function pending(string $id): object
    {
        $awb = $this->store->find('awbs', $id, true);
        abort_unless($awb->status === 'PENDING', 409, 'AWB yang sudah berada di perjalanan tidak dapat diubah.');
        $this->spm($awb->transport_order_id);

        return $awb;
    }

    public function update(string $id, array $input): object
    {
        $d = Validator::make($input, $this->rules() + ['line_ids' => 'nullable|array|min:1|max:500', 'line_ids.*' => 'required|uuid|distinct'])->validate();

        return DB::transaction(function () use ($id, $d) {
            $awb = $this->pending($id);
            $row = ['consignee_name' => $d['consignee_name'] ?? null, 'consignee_phone' => $d['consignee_phone'] ?? null, 'notes' => $d['notes'] ?? null, 'updated_at' => now()];
            if (! empty($d['destination_location_id'])) {
                $row['destination_location_id'] = $d['destination_location_id'];
                $row['destination_text'] = mb_substr($this->store->find('locations', $d['destination_location_id'])->name, 0, 300);
            }
            DB::table('awbs')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($row);
            if (isset($d['line_ids'])) {
                $own = $this->store->query('shipment_lines')->where('awb_id', $id)->pluck('id')->all();
                $this->freeLines($awb->transport_order_id, array_values(array_diff($d['line_ids'], $own)));
                $this->store->query('shipment_lines')->where('awb_id', $id)->whereNotIn('id', $d['line_ids'])->update(['awb_id' => null, 'updated_at' => now()]);
                $this->store->query('shipment_lines')->whereIn('id', $d['line_ids'])->update(['awb_id' => $id, 'updated_at' => now()]);
            }
            $this->store->event('awb.updated', $id, ['number' => $awb->number]);

            return $this->store->find('awbs', $id);
        }, 3);
    }

    public function cancel(string $id, string $reason): object
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason) {
            $awb = $this->pending($id);
            $this->store->query('shipment_lines')->where('awb_id', $id)->update(['awb_id' => null, 'updated_at' => now()]);
            DB::table('awbs')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->store->event('awb.cancelled', $id, ['number' => $awb->number, 'reason' => $reason]);

            return $this->store->find('awbs', $id);
        }, 3);
    }

    /** Called when an SPM is planned: puts the lines that are in no AWB into one AWB for the SPM's destination, and rejects empty AWBs. */
    public function ensureComplete(object $spm): void
    {
        foreach ($this->store->query('awbs')->where('transport_order_id', $spm->id)->where('status', '!=', 'CANCELLED')->get() as $awb) {
            if (! $this->store->query('shipment_lines')->where('awb_id', $awb->id)->exists()) {
                $this->fail('awb', "AWB {$awb->number} tidak memiliki baris muatan.");
            }
        }
        $loose = $this->store->query('shipment_lines')->where('transport_order_id', $spm->id)->whereNull('awb_id')->pluck('id')->all();
        if ($loose !== []) {
            $id = $this->insert($spm, [], $loose);
            $this->store->event('awb.created', $id, ['number' => $this->store->find('awbs', $id)->number, 'spm' => $spm->number, 'lines' => count($loose), 'automatic' => true]);
        }
    }
}
