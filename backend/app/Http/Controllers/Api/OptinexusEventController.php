<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integration\Fleet\FleetEventReceiver;
use App\Domain\Integration\Fleet\WebhookSignature;
use App\Domain\Integration\Optinexus\TenantProvisioner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the OptiNexus event broker (OptiFleet availability, and the assignment of OptiDeli to a tenant). Authenticated by the webhook signature only (no session, no CSRF); the tenant
 * comes from the event's OptiNexus tenant, never from the request. A refusal is a 4xx that OptiNexus does not retry; a
 * deployment without a secret refuses everything.
 */
class OptinexusEventController extends Controller
{
    public function receive(Request $request, FleetEventReceiver $fleet, TenantProvisioner $tenants): JsonResponse
    {
        $valid = WebhookSignature::valid(
            (string) config('optideli.optinexus.events.webhook_secret'),
            (string) $request->header('Opti-Webhook-Id'),
            (string) $request->header('Opti-Webhook-Timestamp'),
            $request->getContent(),
            (string) $request->header('Opti-Webhook-Signature'),
            (int) config('optideli.optinexus.events.tolerance_seconds'),
        );
        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.', 'code' => 'WEBHOOK_SIGNATURE_INVALID'], 401);
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid body.', 'code' => 'WEBHOOK_BODY_INVALID'], 400);
        }

        // Tenant events from OptiNexus itself create the tenant; everything else is a product event (OptiFleet availability).
        return response()->json(['result' => $tenants->handles($event) ? $tenants->provision($event) : $fleet->apply($event)], 202);
    }
}
