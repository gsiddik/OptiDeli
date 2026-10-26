<?php

namespace App\Domain\Planning;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Route templates: a named, ordered list of stops (pickup first, then drops, each with the minutes after the start of the trip at
 * which it is planned). Planning a trip with a template takes the order and the times of its stops; a template can also be saved
 * from a trip that was already planned. A template never changes a trip after the fact.
 */
final class RouteTemplateService
{
    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function exists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->store->tenantId)->where('active', true);
    }

    public function list(?string $search, ?string $active): array
    {
        $query = $this->store->query('route_templates');
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
        }
        if ($active === '1' || $active === '0') {
            $query->where('active', $active === '1');
        }
        $rows = $query->orderBy('name')->get();
        $counts = $this->store->query('route_template_stops')->whereIn('template_id', $rows->pluck('id'))->selectRaw('template_id, count(*) as n')->groupBy('template_id')->pluck('n', 'template_id');
        foreach ($rows as $row) {
            $row->stop_count = (int) ($counts[$row->id] ?? 0);
        }

        return $rows->all();
    }

    public function show(string $id): array
    {
        $template = $this->store->find('route_templates', $id);
        $stops = $this->store->query('route_template_stops')->where('template_id', $id)->orderBy('sequence')->get();
        $places = $this->store->query('locations')->whereIn('id', $stops->pluck('location_id'))->get(['id', 'code', 'name'])->keyBy('id');
        foreach ($stops as $stop) {
            $stop->location_label = isset($places[$stop->location_id]) ? $places[$stop->location_id]->code.' · '.$places[$stop->location_id]->name : null;
        }

        return ['template' => $template, 'stops' => $stops->all()];
    }

    private function rules(bool $creating): array
    {
        $rules = [
            'name' => 'required|string|max:200', 'notes' => 'nullable|string|max:300',
            'carrier_type_id' => ['nullable', 'uuid', $this->exists('carrier_types')], 'service_id' => ['nullable', 'uuid', $this->exists('services')],
            'stops' => 'required|array|min:2|max:50',
            'stops.*.location_id' => ['required', 'uuid', $this->exists('locations')], 'stops.*.type' => 'required|in:PICKUP,DROP', 'stops.*.offset_minutes' => 'nullable|integer|min:0|max:43200',
        ];
        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'];
        }

        return $rules;
    }

    private function checkStops(array $stops): void
    {
        if (($stops[0]['type'] ?? null) !== 'PICKUP') {
            $this->fail('stops', 'Stop pertama harus stop muat.');
        }
        if (count(array_filter($stops, fn ($s) => $s['type'] === 'PICKUP')) !== 1) {
            $this->fail('stops', 'Template memiliki tepat satu stop muat.');
        }
        $last = -1;
        foreach ($stops as $i => $stop) {
            $offset = (int) ($stop['offset_minutes'] ?? 0);
            if ($offset < $last) {
                $this->fail("stops.{$i}.offset_minutes", 'Menit sejak berangkat harus berurutan.');
            }
            $last = $offset;
        }
    }

    public function create(array $input): array
    {
        $d = Validator::make($input, $this->rules(true), [], ['name' => 'Nama', 'code' => 'Kode', 'stops' => 'Stop'])->validate();
        $this->checkStops($d['stops']);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $code = mb_strtoupper($d['code']);
            if ($this->store->query('route_templates')->where('code', $code)->exists()) {
                $this->fail('code', 'Kode template sudah dipakai.');
            }
            $id = (string) Str::uuid();
            DB::table('route_templates')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'code' => $code, 'name' => $d['name'], 'origin_location_id' => $d['stops'][0]['location_id'],
                'carrier_type_id' => $d['carrier_type_id'] ?? null, 'service_id' => $d['service_id'] ?? null, 'notes' => $d['notes'] ?? null, 'active' => true,
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeStops($id, $d['stops']);
            $this->store->event('route_template.created', $id, ['code' => $code, 'stops' => count($d['stops'])]);

            return $this->show($id);
        }, 3);
    }

    private function writeStops(string $id, array $stops): void
    {
        foreach (array_values($stops) as $i => $stop) {
            DB::table('route_template_stops')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'template_id' => $id, 'sequence' => $i + 1, 'location_id' => $stop['location_id'], 'type' => $stop['type'], 'offset_minutes' => (int) ($stop['offset_minutes'] ?? 0)]);
        }
    }

    public function update(string $id, array $input): array
    {
        $d = Validator::make($input, $this->rules(false), [], ['name' => 'Nama', 'stops' => 'Stop'])->validate();
        $this->checkStops($d['stops']);

        return DB::transaction(function () use ($id, $d) {
            $template = $this->store->find('route_templates', $id, true);
            DB::table('route_templates')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update([
                'name' => $d['name'], 'origin_location_id' => $d['stops'][0]['location_id'], 'carrier_type_id' => $d['carrier_type_id'] ?? null, 'service_id' => $d['service_id'] ?? null, 'notes' => $d['notes'] ?? null, 'updated_at' => now(),
            ]);
            DB::table('route_template_stops')->where('tenant_id', $this->store->tenantId)->where('template_id', $id)->delete();
            $this->writeStops($id, $d['stops']);
            $this->store->event('route_template.updated', $id, ['code' => $template->code, 'stops' => count($d['stops'])]);

            return $this->show($id);
        }, 3);
    }

    public function setActive(string $id, bool $active): object
    {
        return DB::transaction(function () use ($id, $active) {
            $template = $this->store->find('route_templates', $id, true);
            DB::table('route_templates')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->store->event($active ? 'route_template.activated' : 'route_template.deactivated', $id, ['code' => $template->code]);

            return $this->store->find('route_templates', $id);
        });
    }

    /** Saves the stops of a planned trip (with the minutes after its start) as a new template. */
    public function fromTrip(string $tripId, array $input): array
    {
        $d = Validator::make($input, ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'name' => 'required|string|max:200'], [], ['code' => 'Kode', 'name' => 'Nama'])->validate();
        $trip = $this->store->find('spm_trips', $tripId);
        $spm = $this->store->find('transport_orders', $trip->transport_order_id);
        $stops = $this->store->query('spm_trip_stops')->where('trip_id', $tripId)->orderBy('sequence')->get()->map(function ($s) use ($trip) {
            $planned = $s->planned_to ?? $s->planned_from;

            return ['location_id' => $s->location_id, 'type' => $s->type, 'offset_minutes' => $planned ? max(0, (int) round((strtotime((string) $planned) - strtotime((string) $trip->starts_at)) / 60)) : 0];
        })->all();
        $last = 0;
        foreach ($stops as &$stop) {
            $stop['offset_minutes'] = max($last, $stop['offset_minutes']);
            $last = $stop['offset_minutes'];
        }

        return $this->create($d + ['carrier_type_id' => $spm->carrier_type_id, 'service_id' => $spm->service_id, 'stops' => $stops]);
    }
}
