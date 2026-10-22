# OptiDeli development checkpoint

## Current state — CP1, foundation/order/dispatch

Owner-approved first delivery increment. Implemented and locally validated; the complete enterprise product remains staged. Development branch: `feat/tms-foundation`; PR targets `main`, with no merge authorization.

## Confirmed owner decisions

- Laravel + PostgreSQL + React/TypeScript, aligned with the Opti ecosystem.
- Delivery sequence: foundation + order/dispatch; then yard/tender/ePOD/audit; then 3D/telematics/ESG/real integrations.
- Margin `(sell − vendor cost) / sell × 100%`, including approved vendor accessorials and customer price components.
- Default 15%; below applicable threshold: ≥10% Supervisor/Fleet Operations Manager, ≥5% Head of Logistics/Operations Director, <5% CFO/Owner.
- Mandatory business reason codes. Per-stage configurability is required; the suggested ranges and OTIF examples do not establish exact automatic defaults.
- Original logo uploaded by owner as `backend/Logo OptiDeli - 1.jpg` in commit `c48daa6`; frontend copy is byte-identical (SHA-256 `9b7d133639433eca87b5943bceb03d90863c8086cce2435c5f6701707ae406f3`). Favicon omitted.

## Delivered

- Same-origin local sessions, CSRF, login throttling, tenant membership checks, viewer protection and standalone tenant binding.
- Order capture with explicit units, price components, draft/confirm/cancel transitions.
- Local vehicle/driver registration, maintenance/unavailability lock, document/eligibility window and freshness checks.
- Immutable dispatch plans, mandatory low-margin reason, independent tiered approval, current-policy checks and confirm-time resource revalidation.
- Idempotency, conservative tenant/resource row locks, tenant-qualified composite foreign keys and unique allocation constraints.
- Atomic audit/outbox. Events are retained but not yet relayed externally.
- Responsive OptiFleet-style navigation/shell, original logo, order/dispatch/fleet/audit/policy UI, honest empty/error/loading states.
- Reproducible PHP development image and Compose workflow, API contract, developer instructions and CI definition.

## Validation evidence

- PostgreSQL suite: 36 tests, 127 assertions, including a race between two separate worker processes: exactly one allocation wins, the other returns 409.
- Frontend: 24 tests; production typecheck/build and lint pass.
- Browser E2E with real API/PostgreSQL: create/confirm order, 11% margin lock, independent Supervisor approval, dispatch, CSRF rejection, logout and login ulang dengan sesi baru. Responsive 1440 / 820 / 390, timezone Asia/Bangkok. Latest run includes original logo; screenshots in [qa](qa/).
- Original logo copy checksum matches; no favicon declared.
- Saved runtime build executed successfully with host trust bundle and existing proxy, without disabling TLS verification. Composer strict validation passes.
- Fresh isolated Compose PostgreSQL/API: migrations succeed; health endpoint returns OK; anonymous order access returns 401; repeat migration is a no-op. Verification containers are stopped after checks; their local volume is preserved.
- GitHub CI for checkpoint `0fbed8b` passed both backend and frontend. The final session regression is locally verified; inspect the PR checks for the latest commit rather than inferring a remote result from local tests.

## Scope boundaries for next increment

1. Execution lifecycle, trip completion/release, multiple legs/orders/consolidation, rebooking/cancellation after dispatch. CP1 conservatively holds one allocation per order/vehicle/driver; there is no resource-release UI yet.
2. Vendor/rate cards/tender, dock/yard, ePOD, post-dispatch accessorial recalculation/payment hold, disputes and freight audit.
3. Real OptiNexus OIDC/MFA, licence verification/node binding, fleet/telematics/accounting adapters, reliable outbox relay and EDI partner contracts.
4. 3D/axle/hazmat optimization, control tower/dynamic ETA, ESG with versioned factors and validated boundaries, and OTIF-based threshold adjustment.
5. Production provisioning, hosting, TLS/HA, retention, backups/recovery, load/security review and licence/distribution terms.

Capacity checks/manual attestation are not a 3D or HOS safety certification. SaaS tenant isolation is tested; SSO/licensing production readiness is not claimed. Unsupported identity mode fails closed.

## Resume instructions

Read README and docs/api/PHASE1.md, inspect git status/remote changes, then continue on the existing review branch. Preserve user commits and local configuration. Use the dedicated `optideli_test` database for migration/reset tests. Keep checkpoint/commit/push updates; never merge the PR without owner request. A usage-limit pause requires platform/session resumption; repository checkpoints preserve work but do not create an autonomous quota-reset scheduler.

## Review PR #1 — owner authorized merge and phase two

- Inspected tenant/auth boundaries, commercial approval snapshots, resource locking, idempotency and UI session handling against the current source and existing tests.
- Found and fixed a numeric-type regression: Laravel integer validation accepts numeric strings, while dispatch policy snapshots compare types strictly. Policy input is now normalized to integers, with a regression test covering policy update → plan → dispatch.
- Integrated the owner's uploaded `Favicon OptiDeli - 1.jpg` without modifying the original file. This supersedes the earlier instruction to leave the favicon empty.
- Merge must use the reviewed PR head after CI passes; phase two starts from the merged `main`, on a new branch.
