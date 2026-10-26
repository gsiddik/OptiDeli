<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Access\Seats;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** The seats of the tenant: who takes one, what the subscription includes and what is above it. Informational: nobody is refused for being over. */
class SeatController extends Controller
{
    public function show(Request $r, Seats $seats)
    {
        abort_unless(Access::can($r, 'access.user.manage') || Access::can($r, 'vendor.portal.provision'), 403, 'Akun Anda tidak boleh melihat kursi.');

        return response()->json(['data' => $seats->summary($r->attributes->get('tenant')->id)]);
    }
}
