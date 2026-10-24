<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integration\Fleet\FleetEventReceiver;
use App\Domain\Integration\Fleet\WebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the OptiNexus event broker. Authenticated by the webhook signature only (no session, no CSRF); the tenant
 * comes from the event's OptiNexus tenant, never from the request. A refusal is a 4xx that OptiNexus does not retry; a
 * deployment without a secret refuses everything.
 */
class OptinexusEventController extends Controller
{
    public function receive(Request $request, FleetEventReceiver $fleet): JsonResponse
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

        return response()->json(['result' => $fleet->apply($event)], 202);
    }
}
