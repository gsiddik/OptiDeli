<?php

namespace App\Domain\Execution;

use Illuminate\Http\Request;

final class Permissions
{
    public static function finance(Request $r): void
    {
        abort_unless(in_array($r->attributes->get('role'), ['finance', 'finance_supervisor', 'cfo'], true), 403, 'Akses Finance diperlukan.');
    }

    public static function verifier(Request $r): void
    {
        abort_unless(in_array($r->attributes->get('role'), ['supervisor', 'head_of_logistics', 'cfo'], true), 403, 'Verifikator operasional diperlukan.');
    }
}
