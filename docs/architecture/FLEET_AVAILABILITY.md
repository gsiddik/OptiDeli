# Vehicle availability from OptiFleet (wave 3, 2026-10-24)

With `FLEET_SERVICE_DRIVER=optifleet`, dispatch asks OptiFleet whether a vehicle may take the trip. OptiDeli's own checks
(documents, driver) always run first (`LocalFleetAvailability`); then `OptiFleetAvailability` decides about the vehicle itself.
The vehicle is matched by registration number (`vehicles.plate`, compared without case, spaces or dashes).

| Tenant has an OptiFleet credential | OptiFleet answers | Result |
|---|---|---|
| yes | yes | the live answer decides (status now, plus planned workshop windows overlapping the trip) |
| yes | no (down, 5xx, unusable) | the last announced state decides; none known: 503, unless `OPTIFLEET_FAIL_OPEN=true` |
| no | - | only announced state counts: ON_HOLD / IN_USE refuses, nothing known allows |

A vehicle OptiFleet does not know (404 `VEHICLE_NOT_FOUND`) is OptiDeli's own and is not blocked. A refusal is 422 with the reason.
The check runs when a dispatch plan is created, when it is confirmed, and when a trip starts (window = planned start to end).

## 1. Announcements (OptiNexus event broker)

OptiFleet writes `optifleet.vehicle.availability_changed` (payload: `vehicle_id`, `registration_number`, `operational_status`
AVAILABLE / IN_USE / ON_HOLD, `previous_operational_status`, `status`, `changed_at`) whenever a vehicle really changes state, for
tenants linked to OptiNexus. OptiNexus delivers it as a signed webhook to
`POST /api/v1/integration/optinexus/events` (no session, CSRF-exempt, authenticated by the signature only):
`Opti-Webhook-Signature: v1=hex(HMAC-SHA256(secret, "<Opti-Webhook-Id>.<Opti-Webhook-Timestamp>.<raw body>"))`, timestamp within
300 s. Wrong or missing signature, expired timestamp, or no `OPTINEXUS_EVENT_WEBHOOK_SECRET`: 401 (OptiNexus does not retry a 4xx).
Otherwise 202 with `result`: `applied`, `stale` (an older event than the stored state, never overwrites), `ignored` (unlinked tenant,
other source or type, bad payload). The tenant is the one linked to the event's OptiNexus tenant, never taken from the request.
Projection: `fleet_vehicle_states` (one row per tenant and plate). It is a hint, never a source of truth.

## 2. Live query

`GET {OPTIFLEET_API_URL}/api/v1/integration/availability?registration_number=&from=&to=` with the tenant's own bearer credential
(scope `fleet.availability.read`, OptiFleet `docs/integration/VEHICLE_AVAILABILITY.md`). Stored encrypted in
`tenants.fleet_availability_token`, never returned by an API. Timeout `OPTIFLEET_TIMEOUT` (default 3 s).

## 3. Operator steps

1. OptiNexus: seed the catalog (`OptiFleetEventCatalogSeeder`), then
   `php artisan ecosystem:register-event-subscription optideli optifleet.vehicle.availability_changed --url=https://<deli-api>/api/v1/integration/optinexus/events --secret-file=<0600 file>`
   (HTTPS required). Put the secret in `OPTINEXUS_EVENT_WEBHOOK_SECRET`. Link the tenant (Superadmin console) and assign both applications.
2. OptiFleet: `php artisan accounting-feed:issue-credential <tenant> --availability` (token shown once).
3. OptiDeli: `php artisan optideli:fleet:token <tenant> --token-file=<file>` (`--clear` removes it); set `FLEET_SERVICE_DRIVER=optifleet`,
   `OPTIFLEET_API_URL`.

`GET /api/v1/fleet/assets/{plate}/readiness` reports the same view (live, else last announcement, else `UNKNOWN`); the earlier
simulated answer is gone.
