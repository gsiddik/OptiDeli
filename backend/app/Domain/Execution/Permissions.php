<?php

namespace App\Domain\Execution;

use App\Domain\Access\Access;
use Illuminate\Http\Request;

final class Permissions
{
    public static function finance(Request $r): void
    {
        Access::authorize($r, 'finance.access', 'Akses Finance diperlukan.');
    }

    public static function verifier(Request $r): void
    {
        Access::authorize($r, 'execution.verify', 'Verifikator operasional diperlukan.');
    }
}
