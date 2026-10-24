<?php

namespace App\Domain\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tenant permission checks. TenantContext resolves the member's permissions once per request; everything else asks here. */
final class Access
{
    /** @return list<string> */
    public static function permissionsFor(int $membershipId): array
    {
        return DB::table('membership_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.membership_id', $membershipId)
            ->distinct()->orderBy('p.code')->pluck('p.code')->all();
    }

    public static function can(Request $r, string $permission): bool
    {
        return in_array($permission, $r->attributes->get('permissions', []), true);
    }

    public static function authorize(Request $r, string $permission, string $message = 'Akses ditolak.'): void
    {
        abort_unless(self::can($r, $permission), 403, $message);
    }

    /** Highest dispatch margin approval level the member holds (0 = none). */
    public static function approvalLevel(Request $r): int
    {
        foreach ([3, 2, 1] as $level) {
            if (self::can($r, "dispatch.approve.level{$level}")) {
                return $level;
            }
        }

        return 0;
    }

    /** True for a membership bound to a vendor: its data is limited to that vendor. */
    public static function isVendor(Request $r): bool
    {
        return $r->attributes->get('vendor_id') !== null;
    }
}
