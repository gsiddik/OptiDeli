# Tracking ingest (geofence capture of stop times)

A system that knows where the vehicles are (a GPS provider, OptiRadar) sends **positions** or **ENTER/EXIT events**; OptiDeli
fills in the arrival and departure of the stops of the running SPM trip of that vehicle. The stop then carries the source
`GEOFENCE` (position) or `RADAR` (event) instead of `MANUAL`, moves the AWBs and the SPM along, and prices overnight and
detention, exactly as a manual entry does. Whatever is not applied is kept in `stop_events` with the reason.

## Credential

`php artisan optideli:tracking:issue-credential <tenant> --acts-as=<member email> [--name=...] [--expires=Y-m-d] [--output=<file>]`
prints (or writes, mode 600) a token `odtk_...` once; only its SHA-256 hash is stored. The token belongs to one tenant and records
what it applies as the member given by `--acts-as` (audit trail, SPM status history). Revoke:
`optideli:tracking:revoke-credential <id>`. The tenant comes from the credential only.

## Endpoints

`Authorization: Bearer odtk_...`, rate limit 600 requests/minute.

### `POST /api/v1/integration/tracking/positions`

```json
{ "plate": "B 1111 AA", "latitude": -6.2005, "longitude": 106.8003, "at": "2026-10-27T08:15:00+07:00" }
```

`vehicle_id` can be given instead of `plate`; `at` defaults to now and may not be in the future. Only a vehicle with a trip in
status `IN_TRANSIT` is considered, and only its **next stop** (the first one not yet left):

* not yet entered and within the radius of the location → `ENTER` (arrival);
* entered and farther than 1.2 × the radius → `EXIT` (departure; the margin absorbs GPS jitter at the edge);
* otherwise nothing changes.

The radius is `geofence_radius_m` of the location (150 m when it is empty); a location without latitude/longitude has no geofence.
Response: `{"data": {"applied": true|false, "action": "ENTER|EXIT|null", "reason": "...", "distance_m": 41, "radius_m": 200, "trip": "PJ-...", "stop": 1}}`.

### `POST /api/v1/integration/tracking/events`

For a geofence engine that decides ENTER/EXIT itself:

```json
{ "plate": "B 1111 AA", "event": "ENTER", "geofence_id": "GF-POOL", "at": "2026-10-27T08:15:00+07:00" }
```

The geofence is the location with that `radar_geofence_id` (field **ID geofence OptiRadar** of the location master), or `location_id`.
It must be the next stop of the trip; a repeated event or one for another stop is logged and not applied.

## Rules that always hold

* Stops are taken in order; a time before the departure from the previous stop is refused.
* A stop time is written once. A correction is made by hand on the trip page, by someone who may manage SPMs.
* The operator screen "Kirim posisi (uji geofence)" runs the same logic for one trip but records the source as `MANUAL`.
