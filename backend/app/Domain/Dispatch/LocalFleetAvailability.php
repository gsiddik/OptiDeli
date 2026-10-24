<?php

namespace App\Domain\Dispatch;

use Carbon\CarbonImmutable;

final class LocalFleetAvailability implements FleetAvailability
{
    public function assertReady(object $vehicle, object $driver, CarbonImmutable $end, ?CarbonImmutable $start = null): void
    {
        abort_unless($vehicle->status === 'AVAILABLE', 422, 'Kendaraan terkunci: status servis atau operasional tidak tersedia.');
        abort_unless(CarbonImmutable::parse($vehicle->readiness_checked_at)->greaterThanOrEqualTo(now()->subMinutes(15)), 422, 'Pemeriksaan kesiapan armada kedaluwarsa; perbarui sebelum dispatch.');
        abort_unless($vehicle->documents_valid_until >= $end->toDateString(), 422, 'Dokumen kendaraan tidak berlaku sampai akhir perjalanan.');
        abort_unless($driver->active && $driver->license_valid_until >= $end->toDateString(), 422, 'SIM pengemudi tidak berlaku sampai akhir perjalanan.');
        abort_unless(CarbonImmutable::parse($driver->eligibility_verified_until)->greaterThanOrEqualTo($end), 422, 'Verifikasi kelayakan/jam kerja pengemudi belum mencakup perjalanan.');
    }
}
