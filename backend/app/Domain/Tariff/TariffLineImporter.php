<?php

namespace App\Domain\Tariff;

use App\Domain\Import\Tabular;
use App\Domain\Master\MasterImporter;
use App\Domain\Shared\TenantStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fills a draft tariff from a spreadsheet. One row is one line; masters are given by their code and prices in whole rupiah.
 * A row that matches an existing line (same lane, carrier, service, charge code and basis) updates its price, or changes
 * nothing when the price is the same. The staged job, its preview and its commit are the ones the master data import uses.
 */
final class TariffLineImporter
{
    /** column key => [header, master it refers to or null] */
    private const COLUMNS = [
        'charge_code' => ['Kode biaya (kode)', 'charge_codes'], 'basis' => ['Dasar tarif', null], 'unit_price' => ['Tarif per satuan (Rp)', null], 'min_charge' => ['Biaya minimum (Rp)', null],
        'origin_location' => ['Asal: lokasi (kode)', 'locations'], 'origin_city' => ['Asal: kota (kode)', 'cities'], 'origin_zone' => ['Asal: zona (kode)', 'zones'],
        'destination_location' => ['Tujuan: lokasi (kode)', 'locations'], 'destination_city' => ['Tujuan: kota (kode)', 'cities'], 'destination_zone' => ['Tujuan: zona (kode)', 'zones'],
        'carrier_type' => ['Tipe armada (kode)', 'carrier_types'], 'service' => ['Layanan (kode)', 'services'],
        'unit' => ['Satuan muatan (kode)', 'units'], 'margin_percent' => ['Margin (%)', null], 'margin_amount' => ['Margin (Rp per satuan)', null],
        'free_time' => ['Free time (jam)', null], 'notes' => ['Catatan', null],
    ];

    public function __construct(private readonly TenantStore $store, private readonly TariffService $tariff, private readonly MasterImporter $importer) {}

    private static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
    }

    /** @return array{0: string, 1: string} */
    public function template(string $format): array
    {
        $headers = array_map(fn ($c) => $c[0], array_values(self::COLUMNS));
        $guide = [
            ['Kode biaya (kode)', 'Ya', 'Kode di master Kode biaya', ''],
            ['Dasar tarif', 'Ya', 'Pilih salah satu', implode('; ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys(TariffService::BASES), TariffService::BASES))],
            ['Tarif per satuan (Rp)', 'Ya', 'Rupiah utuh; titik/koma untuk sen', ''],
            ['Biaya minimum (Rp)', 'Tidak', 'Rupiah utuh', ''],
            ['Asal / Tujuan', 'Tidak', 'Isi salah satu dari lokasi, kota, atau zona per sisi; kosong berarti semua', 'Kode dari master Lokasi, Kota, Zona'],
            ['Tipe armada, Layanan', 'Tidak', 'Kosong berarti semua', 'Kode dari master'],
            ['Satuan muatan (kode)', 'Hanya untuk dasar UNIT', 'Wajib untuk dasar UNIT dan hanya untuk dasar itu', 'Kode dari master Satuan'],
            ['Margin (%) / Margin (Rp per satuan)', 'Tidak', 'Hanya untuk tarif beli milik tenant; isi salah satu. Tarif dasar dihitung otomatis dari tarif awal ditambah margin', 'Contoh: 15 atau 15,5 untuk persen'],
        ];

        return [Tabular::write($format, $format === 'xlsx' ? [['Data', $headers, []], ['Petunjuk', ['Kolom', 'Wajib', 'Format', 'Nilai yang diizinkan'], $guide]] : [['Data', $headers, []]]), "template-baris-tarif.{$format}"];
    }

    private static function rupiah(string $cell): string
    {
        $cell = str_replace(' ', '', $cell);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $cell)) {
            return str_replace(',', '.', str_replace('.', '', $cell));
        }

        return str_replace(',', '.', $cell);
    }

    /** "1500000" or "1500000.5" as minor units, or null when it is not a plain amount. */
    private static function toMinor(string $rupiah): ?int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $rupiah, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** @return array<string, mixed> the staged job */
    public function preview(string $tableId, UploadedFile $file): array
    {
        $table = $this->store->find('rate_tables', $tableId);
        abort_unless($table->status === 'DRAFT', 409, 'Baris hanya dapat diimpor ke tarif yang masih draf.');
        $sheet = Tabular::read($file);

        $lookup = [];
        foreach (self::COLUMNS as $key => [$header]) {
            $lookup[self::key($header)] = $key;
            $lookup[self::key($key)] = $key;
        }
        $map = [];
        $ignored = [];
        foreach ($sheet['headers'] as $i => $header) {
            if (trim($header) === '') {
                continue;
            }
            $key = $lookup[self::key($header)] ?? null;
            if ($key === null) {
                $ignored[] = $header;
            } elseif (in_array($key, $map, true)) {
                throw ValidationException::withMessages(['file' => "Kolom \"{$header}\" muncul lebih dari sekali."]);
            } else {
                $map[$i] = $key;
            }
        }
        foreach (['charge_code', 'basis', 'unit_price'] as $required) {
            if (! in_array($required, $map, true)) {
                throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan: '.self::COLUMNS[$required][0].'. Unduh template untuk judul kolom yang benar.']);
            }
        }
        if ($sheet['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak berisi baris data.']);
        }

        $codes = [];
        foreach (array_unique(array_filter(array_map(fn ($c) => $c[1], self::COLUMNS))) as $master) {
            $codes[$master] = $this->store->query($master)->get(['id', 'code', 'active'])->mapWithKeys(fn ($r) => [mb_strtoupper($r->code) => $r])->all();
        }
        $basisOf = [];
        foreach (TariffService::BASES as $key => $label) {
            $basisOf[mb_strtolower($key)] = $key;
            $basisOf[mb_strtolower($label)] = $key;
        }

        $counts = ['CREATE' => 0, 'UPDATE' => 0, 'NONE' => 0, 'ERROR' => 0];
        $records = [];
        $seen = [];
        foreach ($sheet['rows'] as [$sheetRow, $cells]) {
            $raw = [];
            foreach ($map as $i => $key) {
                $raw[$key] = ($cells[$i] ?? '') === '' ? null : $cells[$i];
            }
            $errors = [];
            $input = [];
            foreach (self::COLUMNS as $key => [, $master]) {
                $cell = $raw[$key] ?? null;
                if ($cell === null || $master === null) {
                    continue;
                }
                $hit = $codes[$master][mb_strtoupper($cell)] ?? null;
                if ($hit === null) {
                    $errors[$key][] = 'Kode "'.$cell.'" tidak ditemukan.';
                } elseif (! $hit->active) {
                    $errors[$key][] = 'Kode "'.$cell.'" tidak aktif.';
                } else {
                    $input[$key] = $hit->id;
                }
            }
            $line = [
                'charge_code_id' => $input['charge_code'] ?? null, 'origin_location_id' => $input['origin_location'] ?? null, 'origin_city_id' => $input['origin_city'] ?? null, 'origin_zone_id' => $input['origin_zone'] ?? null,
                'destination_location_id' => $input['destination_location'] ?? null, 'destination_city_id' => $input['destination_city'] ?? null, 'destination_zone_id' => $input['destination_zone'] ?? null,
                'carrier_type_id' => $input['carrier_type'] ?? null, 'service_id' => $input['service'] ?? null, 'unit_id' => $input['unit'] ?? null,
                'basis' => isset($raw['basis']) ? ($basisOf[mb_strtolower($raw['basis'])] ?? $raw['basis']) : null,
                'free_time_hours' => $raw['free_time'] ?? null, 'notes' => $raw['notes'] ?? null,
            ];
            foreach (['unit_price' => 'unit_price_minor', 'min_charge' => 'min_charge_minor'] as $col => $field) {
                if (($raw[$col] ?? null) === null) {
                    continue;
                }
                $minor = self::toMinor(self::rupiah($raw[$col]));
                $minor === null ? $errors[$col][] = 'Nominal tidak valid: "'.$raw[$col].'".' : $line[$field] = $minor;
            }
            if (($raw['margin_percent'] ?? null) !== null && ($raw['margin_amount'] ?? null) !== null) {
                $errors['margin_percent'][] = 'Isi margin persen atau margin nominal, tidak keduanya.';
            } elseif (($raw['margin_percent'] ?? null) !== null) {
                $pct = str_replace(',', '.', trim(str_replace('%', '', $raw['margin_percent'])));
                preg_match('/^\d{1,4}(\.\d{1,2})?$/', $pct) ? $line += ['margin_mode' => 'PERCENT', 'margin_value' => (int) round((float) $pct * 100)] : $errors['margin_percent'][] = 'Margin persen tidak valid: "'.$raw['margin_percent'].'".';
            } elseif (($raw['margin_amount'] ?? null) !== null) {
                $minor = self::toMinor(self::rupiah($raw['margin_amount']));
                $minor === null ? $errors['margin_amount'][] = 'Nominal tidak valid: "'.$raw['margin_amount'].'".' : $line += ['margin_mode' => 'AMOUNT', 'margin_value' => $minor];
            }
            $data = null;
            $action = 'ERROR';
            $target = null;
            if ($errors === []) {
                [$data, $errors] = $this->tariff->checkLine($table, $line);
                if ($data !== null) {
                    $signature = md5(json_encode(array_intersect_key($data, array_flip(array_merge(array_keys(TariffService::LINE_REFS), ['basis'])))));
                    if (isset($seen[$signature])) {
                        $errors['charge_code'][] = "Baris sama dengan baris {$seen[$signature]}.";
                        $data = null;
                    } else {
                        $seen[$signature] = $sheetRow;
                        $existing = $this->tariff->findLine($tableId, $data);
                        if ($existing === null) {
                            $action = 'CREATE';
                        } elseif ((int) $existing->unit_price_minor === $data['unit_price_minor'] && (int) $existing->min_charge_minor === $data['min_charge_minor'] && $existing->margin_mode === $data['margin_mode'] && ($existing->margin_value === null ? null : (int) $existing->margin_value) === $data['margin_value']) {
                            $action = 'NONE';
                            $target = $existing->id;
                        } else {
                            $action = 'UPDATE';
                            $target = $existing->id;
                        }
                    }
                }
            }
            if ($errors !== []) {
                $action = 'ERROR';
                $data = null;
            }
            $counts[$action]++;
            $raw['code'] = $raw['charge_code'] ?? null;
            $raw['name'] = trim(($raw['origin_location'] ?? $raw['origin_city'] ?? $raw['origin_zone'] ?? 'Semua').' → '.($raw['destination_location'] ?? $raw['destination_city'] ?? $raw['destination_zone'] ?? 'Semua'));
            $records[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'row_number' => $sheetRow, 'action' => $action, 'target_id' => in_array($action, ['UPDATE'], true) ? $target : null,
                'raw' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'data' => $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
        }

        return $this->importer->stage('rate_lines', $file, $sheet, ['map' => $map, 'ignored' => $ignored, 'table_id' => $tableId], $counts, $records);
    }
}
