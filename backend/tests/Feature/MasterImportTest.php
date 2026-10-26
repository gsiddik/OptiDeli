<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Excel/CSV import and export of the master data (F1/M1). */
class MasterImportTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function upload(string $resource, string $csv, string $name = 'data.csv')
    {
        return $this->post("/api/v1/master/{$resource}/imports", ['file' => UploadedFile::fake()->createWithContent($name, $csv)], $this->key() + ['Accept' => 'application/json']);
    }

    private function customers(): string
    {
        return "Kode;Nama;Termin bayar (hari)\nc-1;PT Satu;30\nc-2;PT Dua;45\n";
    }

    public function test_templates_and_exports_download_in_both_formats(): void
    {
        foreach (['csv', 'xlsx'] as $format) {
            $this->get("/api/v1/master/customers/template?format={$format}")->assertOk()->assertDownload("template-customers.{$format}");
        }
        $this->postJson('/api/v1/master/customers', ['code' => 'A1', 'name' => 'PT A', 'payment_terms_days' => 30], $this->key())->assertCreated();
        $csv = $this->get('/api/v1/master/customers/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('A1;', $csv);
        $this->assertStringContainsString('PT A', $csv);
        $this->get('/api/v1/master/customers/export')->assertOk();
    }

    public function test_an_exported_xlsx_can_be_imported_into_another_tenant(): void
    {
        $this->postJson('/api/v1/master/customers', ['code' => 'A1', 'name' => 'PT A', 'payment_terms_days' => 21], $this->key())->assertCreated();
        $path = $this->get('/api/v1/master/customers/export?format=xlsx')->assertOk()->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        copy($path, $copy);

        $this->signInTenantMember('dispatcher', 'second');
        $job = $this->post('/api/v1/master/customers/imports', ['file' => new UploadedFile($copy, 'export.xlsx', null, null, true)], $this->key() + ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(1, $job['rows_new']);
        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertOk();
        $this->assertDatabaseHas('customers', ['code' => 'A1', 'name' => 'PT A', 'payment_terms_days' => 21]);
        @unlink($copy);
    }

    public function test_a_csv_is_previewed_without_writing_and_then_applied_once(): void
    {
        $job = $this->upload('customers', $this->customers())->assertCreated()->json('data');
        $this->assertSame(2, $job['rows_new']);
        $this->assertSame(0, $job['rows_error']);
        $this->assertDatabaseMissing('customers', ['tenant_id' => $this->tenant, 'code' => 'C-1']);

        $done = $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertOk()->json('data');
        $this->assertSame('COMMITTED', $done['status']);
        $this->assertSame(2, $done['result']['created']);
        $this->assertDatabaseHas('customers', ['tenant_id' => $this->tenant, 'code' => 'C-1', 'name' => 'PT Satu', 'payment_terms_days' => 30]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'master.import.committed']);

        // Committing again changes nothing.
        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertOk();
        $this->assertSame(2, \DB::table('customers')->where('tenant_id', $this->tenant)->count());

        // The same file again is flagged, and now holds no change.
        $again = $this->upload('customers', $this->customers())->assertCreated()->json('data');
        $this->assertNotNull($again['already_imported_at']);
        $this->assertSame(2, $again['rows_unchanged']);
        $this->assertSame(0, $again['rows_new']);
    }

    public function test_an_existing_code_is_updated_and_blank_cells_keep_the_value(): void
    {
        $this->postJson('/api/v1/master/customers', ['code' => 'C-1', 'name' => 'PT Lama', 'payment_terms_days' => 30, 'pic_name' => 'Budi'], $this->key())->assertCreated();
        $job = $this->upload('customers', "Kode;Nama;Termin bayar (hari)\nC-1;PT Baru;\n")->assertCreated()->json('data');
        $this->assertSame(1, $job['rows_update']);
        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertOk();

        $this->assertDatabaseHas('customers', ['tenant_id' => $this->tenant, 'code' => 'C-1', 'name' => 'PT Baru', 'payment_terms_days' => 30, 'pic_name' => 'Budi']);
    }

    public function test_bad_rows_are_reported_and_block_the_commit_unless_skipped(): void
    {
        $csv = "Kode;Nama;Termin bayar (hari)\nOK1;PT Baik;30\nbad code;PT Buruk;30\nOK1;PT Kembar;30\nOK3;;30\n";
        $job = $this->upload('customers', $csv)->assertCreated()->json('data');
        $this->assertSame(1, $job['rows_new']);
        $this->assertSame(3, $job['rows_error']);

        $rows = $this->getJson("/api/v1/master/imports/{$job['id']}/rows?action=ERROR")->assertOk()->json('data');
        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows[0]['row']);

        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertUnprocessable();
        $this->assertDatabaseMissing('customers', ['code' => 'OK1']);

        $this->get("/api/v1/master/imports/{$job['id']}/errors?format=csv")->assertOk()->assertDownload('baris-gagal-customers.csv');

        $done = $this->postJson("/api/v1/master/imports/{$job['id']}/commit", ['skip_errors' => true], $this->key())->assertOk()->json('data');
        $this->assertSame(1, $done['result']['created']);
        $this->assertSame(3, $done['result']['skipped_errors']);
        $this->assertDatabaseHas('customers', ['tenant_id' => $this->tenant, 'code' => 'OK1', 'name' => 'PT Baik']);
    }

    public function test_references_are_given_as_codes_and_must_exist(): void
    {
        $this->postJson('/api/v1/master/customers', ['code' => 'C-1', 'name' => 'PT Satu', 'payment_terms_days' => 30], $this->key())->assertCreated();
        $csv = "Kode;Nama;Pelanggan (kode)\nP-1;Proyek Satu;c-1\nP-2;Proyek Dua;NOPE\n";
        $job = $this->upload('customer_projects', $csv)->assertCreated()->json('data');
        $this->assertSame(1, $job['rows_new']);
        $this->assertSame(1, $job['rows_error']);
        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", ['skip_errors' => true], $this->key())->assertOk();
        $this->assertDatabaseHas('customer_projects', ['tenant_id' => $this->tenant, 'code' => 'P-1']);
    }

    public function test_the_file_is_checked(): void
    {
        $this->upload('customers', "Foo;Bar\n1;2\n")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('customers', "Kode;Nama\n")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('customers', 'x', 'data.txt')->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('customers', "Kode;Kode;Nama\nA;B;C\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_discarded_import_cannot_be_applied(): void
    {
        $job = $this->upload('customers', $this->customers())->assertCreated()->json('data');
        $this->postJson("/api/v1/master/imports/{$job['id']}/discard", [], $this->key())->assertOk()->assertJsonPath('data.status', 'DISCARDED');
        $this->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertStatus(409);
    }

    public function test_another_tenant_cannot_see_the_job(): void
    {
        $job = $this->upload('customers', $this->customers())->assertCreated()->json('data');
        $this->signInTenantMember('dispatcher', 'other');
        $this->getJson("/api/v1/master/imports/{$job['id']}")->assertNotFound();
    }

    public function test_importing_needs_master_manage(): void
    {
        $this->signInTenantMember('viewer', 'ro');
        $this->upload('customers', $this->customers())->assertForbidden();
        $this->get('/api/v1/master/customers/template')->assertOk();
    }
}
