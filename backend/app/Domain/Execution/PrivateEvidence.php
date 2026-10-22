<?php

namespace App\Domain\Execution;

use Illuminate\Support\Facades\Storage;

final class PrivateEvidence
{
    public static function valid(object $record): bool
    {
        return Storage::disk('local')->exists($record->path) && hash_equals($record->sha256, hash('sha256', Storage::disk('local')->get($record->path)));
    }
}
