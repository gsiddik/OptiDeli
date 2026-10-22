# Phase-one HTTP contract

All paths are prefixed `/api/v1`. API and SPA must share an origin. This contract describes CP1, not the illustrative external URLs or EDI endpoints in the blueprint.

## Session and errors

Fetch `GET /csrf` for a session-bound CSRF token; send it in `X-CSRF-TOKEN` on mutations, with same-origin cookies and `Accept: application/json`. Login regenerates the session; logout invalidates it. No bearer token is stored in browser localStorage.

- Public: `GET /health`, `GET /csrf`, `POST /auth/login` (`email`, `password`, `tenant` slug).
- Authenticated: `GET /auth/me`, `POST /auth/logout`. Logout remains possible after tenant membership is revoked.
- Business routes require current active tenant membership; SaaS tenant comes from login-bound session. Standalone also enforces configured tenant slug.
- Errors: 401 missing auth; 403 access/tenant/approval authority; 404 tenant-qualified resource not found; 409 stale state/idempotency conflict/allocation conflict; 419 CSRF/session mismatch; 422 validation/domain rule; 429 throttle; 503 unsupported identity/deployment mode.
- Error JSON includes `message`; field-validation errors include `errors: {field: string[]}`. Successful object responses use `{data: ...}`. Lists use Laravel pagination (`data`, `current_page`, `last_page`, `total`).

## Routes

| Method / path | Contract |
|---|---|
| GET `/dashboard` | Tenant order status counts, pending approvals, fleet status count, unpublished outbox count |
| GET `/orders` | `page`, optional `status`, `q`; 25 records/page |
| POST `/orders` | Draft creation, `Idempotency-Key` required |
| GET `/orders/{uuid}` | Order with items and plans, tenant-qualified |
| POST `/orders/{uuid}/transition` | `{action: confirm\|cancel}`; cancel only DRAFT/CONFIRMED |
| GET `/fleet` | Tenant vehicles, drivers and allocation IDs |
| POST `/fleet/vehicles` | Local vehicle registration and initial readiness statement |
| POST `/fleet/vehicles/{uuid}/readiness` | `{status: AVAILABLE\|MAINTENANCE\|UNAVAILABLE, note}`; note 10–1000 characters |
| POST `/fleet/drivers` | Driver registration with licence validity and manually verified eligibility window |
| GET `/dispatch/plans` | Paginated tenant plans, newest first |
| POST `/dispatch/plans` | Immutable plan creation, `Idempotency-Key` required |
| POST `/dispatch/plans/{uuid}/approve` | Independent tier-authorized approval; no price/status fields accepted |
| POST `/dispatch/plans/{uuid}/confirm` | Revalidate state/policy/resources; transactionally allocate; repeated success returns existing trip |
| GET `/commercial/policy` | Default/stage thresholds in basis points and policy version |
| PUT `/commercial/policy` | CFO/Owner only: `minimum_margin_bps`, `stage_margin_bps`, `reason` (20–2000 characters) |
| GET `/audit` | 50 tenant audit events/page, read only |

Viewer cannot mutate business data. Dispatcher and elevated roles can operate orders/resources/dispatch. Supervisor, Head of Logistics and CFO/Owner map to approval levels 1, 2, 3 respectively; higher levels can approve lower levels, but never their own request.

## Order body

Required: `customer_name`, `stage` (`FIRST_MILE`, `MIDDLE_MILE`, `LAST_MILE`, `E2E`), `origin`, `destination`, `currency: IDR`, `base_price_minor`, `surcharge_minor`, `accessorial_minor`, `items` (1–100). Optional `reference`.

Each item: `description`, `quantity` (1–1000), `unit_weight_g` (1–100000000), `length_mm`, `width_mm`, `height_mm` (1–20000), `stackable` boolean, optional `hazmat_class`. Nested unexpected keys are rejected. Hazmat can be recorded but cannot pass CP1 dispatch safety checks. Order price must be positive in total. Amount components are nonnegative integers, max 1000000000000 each; do not send decimal floats.

## Dispatch body

`order_id`, `vehicle_id`, `driver_id` UUIDs; `vendor_base_minor`, `vendor_accessorial_minor` as nonnegative integer minor units (max 1000000000000 each); `starts_at` and `ends_at` as ISO timestamps with explicit `Z`/UTC offset; `ends_at` after `starts_at`; `load_safety_attestation` (20–2000 characters). Optional `reason_detail` (10–2000); `reason_code` is required if below threshold, one of `CUSTOMER_RETENTION`, `BACKHAUL`, `CONTRACT_COMMITMENT`.

Plans do not reserve resources until confirm. Policy version/threshold, total cost, requesting actor, required level and approval status are computed server-side. Dates stored in UTC. Capacity and readiness are checked at both planning and confirmation; stale readiness, maintenance, insufficient eligibility, hazmat and capacity violations reject the action.

## Registration bodies

Vehicle: `plate`, `type`, `capacity_g`, `length_mm`, `width_mm`, `height_mm`, `documents_valid_until` (`YYYY-MM-DD`, earliest required-document expiry). Driver: `name`, `license_number`, `license_valid_until` (`YYYY-MM-DD`), `eligibility_verified_until` (ISO timestamp with zone). Registration is a privileged local operational attestation, not external document verification or a live OptiFleet/HOS integration.

## Idempotency and integration

`Idempotency-Key`: 16–100 ASCII letters/digits/underscore/hyphen. Keep it unchanged for a retry of the same creation request. Reuse with changed validated content returns 409. Keys are tenant/operation scoped. Retention/expiry is not yet automated.

Audit and outbox records commit with state changes. Outbox event types are versioned `.v1` and include tenant, subject, event ID and timestamp. No relay, webhook delivery, accounting journal creation or EDI interchange is enabled in CP1.
