<?php

namespace App\Domain\Master;

/**
 * The master data of the transport operation, described once. The same definition drives validation, the API, the audit
 * trail and the generic screen (`GET master/schema`), so adding a master or a field is a change here and a migration.
 *
 * Field keys: name, label, kind (text, textarea, number, decimal, select, boolean, date, ref), required, max, min,
 * options (value => label, for select), ref (resource name, for ref), list (shown in the table), immutable (cannot change after
 * creation), unique (unique per tenant besides the code), upper (stored upper case), digits (stored as digits only),
 * rules (extra Laravel rules), hint.
 */
final class MasterRegistry
{
    public const GROUPS = [
        'customers' => 'Pelanggan',
        'regional' => 'Wilayah',
        'network' => 'Jaringan',
        'cargo' => 'Muatan & layanan',
        'billing' => 'Penagihan',
    ];

    private const CODE = ['name' => 'code', 'label' => 'Kode', 'kind' => 'text', 'required' => true, 'max' => 40, 'immutable' => true, 'upper' => true, 'list' => true,
        'rules' => ['regex:/^[A-Z0-9][A-Z0-9._\/-]*$/'], 'hint' => 'Huruf besar, angka, titik, garis miring, garis bawah atau strip. Tidak dapat diubah setelah dibuat.'];

    private const NAME = ['name' => 'name', 'label' => 'Nama', 'kind' => 'text', 'required' => true, 'max' => 200, 'list' => true];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'customers' => [
                'label' => 'Pelanggan', 'singular' => 'pelanggan', 'group' => 'customers', 'search' => ['code', 'name', 'tax_id'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'tax_id', 'label' => 'NPWP', 'kind' => 'text', 'max' => 32, 'digits' => true, 'list' => true, 'rules' => ['regex:/^\d{15,16}$/'], 'hint' => '15 atau 16 digit; titik dan strip diabaikan.'],
                    ['name' => 'payment_terms_days', 'label' => 'Termin bayar (hari)', 'kind' => 'number', 'required' => true, 'min' => 0, 'max' => 365, 'default' => 30, 'list' => true],
                    ['name' => 'pic_name', 'label' => 'PIC', 'kind' => 'text', 'max' => 150],
                    ['name' => 'pic_phone', 'label' => 'Telepon PIC', 'kind' => 'text', 'max' => 40],
                    ['name' => 'pic_email', 'label' => 'Email PIC', 'kind' => 'text', 'max' => 254, 'rules' => ['email']],
                    ['name' => 'address', 'label' => 'Alamat', 'kind' => 'textarea', 'max' => 1000],
                    ['name' => 'invoice_template_id', 'label' => 'Template invoice', 'kind' => 'ref', 'ref' => 'invoice_templates', 'hint' => 'Kosong = template standar (satu invoice per SPM).'],
                    ['name' => 'notes', 'label' => 'Catatan', 'kind' => 'textarea', 'max' => 1000],
                ],
            ],
            'customer_projects' => [
                'label' => 'Proyek / kontrak', 'singular' => 'proyek', 'group' => 'customers', 'search' => ['code', 'name', 'contract_reference'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'customer_id', 'label' => 'Pelanggan', 'kind' => 'ref', 'ref' => 'customers', 'required' => true, 'immutable' => true, 'list' => true],
                    ['name' => 'contract_reference', 'label' => 'Nomor kontrak', 'kind' => 'text', 'max' => 120, 'list' => true],
                    ['name' => 'valid_from', 'label' => 'Berlaku dari', 'kind' => 'date'],
                    ['name' => 'valid_until', 'label' => 'Berlaku sampai', 'kind' => 'date', 'rules' => ['after_or_equal:valid_from']],
                    ['name' => 'payment_terms_days', 'label' => 'Termin bayar (hari)', 'kind' => 'number', 'min' => 0, 'max' => 365, 'hint' => 'Kosong = ikut termin pelanggan.'],
                    ['name' => 'invoice_template_id', 'label' => 'Template invoice', 'kind' => 'ref', 'ref' => 'invoice_templates', 'hint' => 'Kosong = ikut template pelanggan.'],
                ],
            ],
            'regions' => [
                'label' => 'Provinsi', 'singular' => 'provinsi', 'group' => 'regional', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME],
            ],
            'cities' => [
                'label' => 'Kota / kabupaten', 'singular' => 'kota', 'group' => 'regional', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'region_id', 'label' => 'Provinsi', 'kind' => 'ref', 'ref' => 'regions', 'required' => true, 'list' => true],
                ],
            ],
            'zones' => [
                'label' => 'Zona', 'singular' => 'zona', 'group' => 'regional', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME, ['name' => 'description', 'label' => 'Keterangan', 'kind' => 'text', 'max' => 300, 'list' => true]],
            ],
            'areas' => [
                'label' => 'Area', 'singular' => 'area', 'group' => 'regional', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'city_id', 'label' => 'Kota / kabupaten', 'kind' => 'ref', 'ref' => 'cities', 'list' => true],
                    ['name' => 'zone_id', 'label' => 'Zona', 'kind' => 'ref', 'ref' => 'zones', 'list' => true],
                ],
            ],
            'locations' => [
                'label' => 'Lokasi', 'singular' => 'lokasi', 'group' => 'network', 'search' => ['code', 'name', 'address'],
                'fields' => [
                    self::CODE,
                    ['name' => 'name', 'label' => 'Nama', 'kind' => 'text', 'required' => true, 'max' => 200, 'list' => true, 'unique' => true],
                    ['name' => 'type', 'label' => 'Jenis', 'kind' => 'select', 'required' => true, 'list' => true,
                        'options' => ['POOL' => 'Pool kendaraan', 'WAREHOUSE' => 'Gudang', 'CUSTOMER' => 'Lokasi pelanggan', 'HUB' => 'Hub / cross-dock', 'OTHER' => 'Lainnya']],
                    ['name' => 'customer_id', 'label' => 'Pelanggan pemilik', 'kind' => 'ref', 'ref' => 'customers', 'list' => true],
                    ['name' => 'address', 'label' => 'Alamat', 'kind' => 'textarea', 'max' => 1000],
                    ['name' => 'city_id', 'label' => 'Kota / kabupaten', 'kind' => 'ref', 'ref' => 'cities', 'list' => true],
                    ['name' => 'area_id', 'label' => 'Area', 'kind' => 'ref', 'ref' => 'areas'],
                    ['name' => 'latitude', 'label' => 'Lintang (latitude)', 'kind' => 'decimal', 'min' => -90, 'max' => 90, 'rules' => ['required_with:longitude,geofence_radius_m'], 'hint' => 'Contoh -6.175110. Isi bersama bujur.'],
                    ['name' => 'longitude', 'label' => 'Bujur (longitude)', 'kind' => 'decimal', 'min' => -180, 'max' => 180, 'rules' => ['required_with:latitude,geofence_radius_m'], 'hint' => 'Contoh 106.865039.'],
                    ['name' => 'geofence_radius_m', 'label' => 'Radius geofence (meter)', 'kind' => 'number', 'min' => 10, 'max' => 50000, 'hint' => 'Dipakai untuk jam masuk/keluar otomatis; perlu koordinat.'],
                    ['name' => 'radar_geofence_id', 'label' => 'ID geofence OptiRadar', 'kind' => 'text', 'max' => 80, 'hint' => 'Diisi bila geofence stop ini dikelola di OptiRadar; dipakai untuk mencocokkan kejadian ENTER/EXIT.'],
                    ['name' => 'contact_name', 'label' => 'Kontak', 'kind' => 'text', 'max' => 150],
                    ['name' => 'contact_phone', 'label' => 'Telepon kontak', 'kind' => 'text', 'max' => 40],
                ],
            ],
            'carrier_types' => [
                'label' => 'Tipe armada', 'singular' => 'tipe armada', 'group' => 'network', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'capacity_g', 'label' => 'Kapasitas muat (gram)', 'kind' => 'number', 'min' => 1, 'max' => 100000000, 'list' => true],
                    ['name' => 'length_mm', 'label' => 'Panjang bak (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'width_mm', 'label' => 'Lebar bak (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'height_mm', 'label' => 'Tinggi bak (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'description', 'label' => 'Keterangan', 'kind' => 'text', 'max' => 300],
                ],
            ],
            'units' => [
                'label' => 'Satuan', 'singular' => 'satuan', 'group' => 'cargo', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME],
            ],
            'category_types' => [
                'label' => 'Kategori barang', 'singular' => 'kategori', 'group' => 'cargo', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME],
            ],
            'goods' => [
                'label' => 'Barang', 'singular' => 'barang', 'group' => 'cargo', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'unit_id', 'label' => 'Satuan', 'kind' => 'ref', 'ref' => 'units', 'list' => true],
                    ['name' => 'category_id', 'label' => 'Kategori', 'kind' => 'ref', 'ref' => 'category_types', 'list' => true],
                    ['name' => 'unit_weight_g', 'label' => 'Berat per satuan (gram)', 'kind' => 'number', 'min' => 1, 'max' => 100000000],
                    ['name' => 'length_mm', 'label' => 'Panjang (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'width_mm', 'label' => 'Lebar (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'height_mm', 'label' => 'Tinggi (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'stackable', 'label' => 'Dapat ditumpuk', 'kind' => 'boolean', 'required' => true, 'default' => true],
                    ['name' => 'hazmat_class', 'label' => 'Kelas bahan berbahaya', 'kind' => 'text', 'max' => 20, 'hint' => 'Kosong bila bukan B3.'],
                ],
            ],
            'services' => [
                'label' => 'Layanan', 'singular' => 'layanan', 'group' => 'cargo', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'sla_hours', 'label' => 'Target waktu (jam)', 'kind' => 'number', 'min' => 1, 'max' => 720, 'list' => true],
                    ['name' => 'description', 'label' => 'Keterangan', 'kind' => 'text', 'max' => 300],
                ],
            ],
            'invoice_templates' => [
                'label' => 'Template invoice', 'singular' => 'template invoice', 'group' => 'billing', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'grouping', 'label' => 'Pengelompokan', 'kind' => 'select', 'required' => true, 'list' => true, 'default' => 'PER_SPM',
                        'options' => ['PER_SPM' => 'Satu invoice per SPM', 'PER_PROJECT' => 'Satu invoice per proyek', 'PER_PERIOD' => 'Satu invoice per periode (semua SPM siap tagih)']],
                    ['name' => 'split_plan_unplan', 'label' => 'Pisahkan Plan dan Unplan', 'kind' => 'boolean', 'required' => true, 'default' => false, 'list' => true, 'hint' => 'Biaya sesuai tarif (Plan) dan di luar tarif (Unplan) ditagih di invoice terpisah.'],
                    ['name' => 'columns', 'label' => 'Kolom rincian', 'kind' => 'text', 'required' => true, 'max' => 200, 'default' => 'spm,route,description,amount',
                        'rules' => ['regex:/^(spm|order|route|date|description|qty|unit_price|amount)(,(spm|order|route|date|description|qty|unit_price|amount))*$/'],
                        'hint' => 'Daftar dipisah koma, urut tampil: spm, order, route, date, description, qty, unit_price, amount.'],
                    ['name' => 'due_days', 'label' => 'Jatuh tempo (hari)', 'kind' => 'number', 'min' => 0, 'max' => 365, 'hint' => 'Kosong = ikut termin proyek atau pelanggan.'],
                    ['name' => 'footer', 'label' => 'Catatan kaki invoice', 'kind' => 'text', 'max' => 500],
                ],
            ],
            'charge_codes' => [
                'label' => 'Kode biaya', 'singular' => 'kode biaya', 'group' => 'cargo', 'search' => ['code', 'name', 'fact_key'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'side', 'label' => 'Sisi', 'kind' => 'select', 'required' => true, 'list' => true,
                        'options' => ['SELL' => 'Pendapatan (jual)', 'BUY' => 'Biaya (beli)', 'BOTH' => 'Keduanya']],
                    ['name' => 'nature', 'label' => 'Jenis', 'kind' => 'select', 'required' => true, 'list' => true,
                        'options' => ['FREIGHT' => 'Ongkos angkut', 'ACCESSORIAL' => 'Biaya tambahan', 'ALLOWANCE' => 'Uang jalan / operasional', 'OTHER' => 'Lainnya']],
                    ['name' => 'fact_key', 'label' => 'Kunci fakta', 'kind' => 'text', 'required' => true, 'max' => 60, 'upper' => true, 'list' => true,
                        'rules' => ['regex:/^[A-Z][A-Z0-9_]*$/'], 'hint' => 'Dipakai OptiEntry untuk memetakan ke akun, misalnya UNLOADING atau DRIVER_ADVANCE. OptiDeli tidak menyimpan akun.'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function fieldsOf(string $resource): array
    {
        return self::find($resource)['fields'] ?? [];
    }

    /** What the screen needs to draw every master, without server-only rules. */
    public static function schema(): array
    {
        $resources = [];
        foreach (self::all() as $key => $definition) {
            $resources[] = [
                'key' => $key, 'label' => $definition['label'], 'singular' => $definition['singular'], 'group' => $definition['group'],
                'fields' => array_map(fn (array $f) => array_diff_key($f, ['rules' => 1]), $definition['fields']),
            ];
        }

        return ['groups' => self::GROUPS, 'resources' => $resources];
    }
}
