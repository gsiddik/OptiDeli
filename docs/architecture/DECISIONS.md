# Architecture and product review — CP1

## Delivery boundary

The document is an enterprise blueprint, not a complete executable contract. CP1 is a working vertical slice: tenant-bound local sessions, role authorization, order capture, confirmation, local fleet/driver readiness, guarded dispatch, audit and transactional outbox. The remaining domains stay on the product backlog. No simulated integration is labelled live.

Laravel 13 / PostgreSQL / React 19 + TypeScript follows the existing Opti ecosystem. The owner confirmed this stack and staged delivery during implementation. Modular monolith boundaries are Identity, Orders, Dispatch, Commercial and Integration; domain arithmetic is independent of HTTP and persistence. Services own transactions and invariants. Ports describe external availability adapters; CP1 supports a local register only. Shared-database SaaS uses tenant discriminators plus composite foreign keys; standalone additionally binds to a configured tenant slug. No arbitrary tenant header determines authority.

## Cross-functional design review

These are design perspectives applied to the specification, not claims of external certification or interviews.

| Perspective | Risk found | Resulting implementation / pending decision |
|---|---|---|
| Product | Seven modules exceed one verifiable initial increment | Track acceptance by checkpoint; surface future capabilities as planned, not functioning dashboards |
| Transport operator | Dispatch of maintenance-blocked, already allocated or expired-document assets | Recheck within the dispatch transaction, lock shared resources, enforce active-resource uniqueness |
| Logistics operations | Cubic volume alone does not prove cargo fits or axle safety | Capacity checks are prerequisites only; CP1 rejects hazmat and oversized items, and requires manual load-safety attestation; no optimizer claim |
| Commercial owner | Below-margin dispatch can silently destroy contribution | Exact integer arithmetic, owner-approved 15% default, mandatory reason code and independent tiered approval |
| Architecture | Spoofed tenant IDs or cross-tenant resource references | Membership-derived context; tenant-qualified queries and DB relationships; isolation tests |
| Backend | Double click / retries allocate twice | Idempotency keys, request fingerprints, DB transaction, per-tenant uniqueness, row locks, outbox atomicity |
| Frontend | Happy-path mock data conceals failures | Real API only, visible empty/error/loading states, labelled units, server-side validation, retry-safe form submissions |
| Finance | Dispatch estimate is not a journal or payable | Separate estimated vendor cost from invoice settlement; no accounting mutation in CP1 |

## Money and margin

Visual inspection of the DOCX's transparent embedded images recovered:

- Customer sell = customer base rate(stage) + fuel surcharge + customer accessorials.
- Vendor cost = vendor base rate(stage,type,zone,distance) + approved vendor accessorials.
- Margin = (sell − vendor cost) / sell × 100%.
- The original illustration used **15% as an example**; the owner subsequently explicitly approved it as the platform default.

Amounts are integers in currency minor units with an explicit ISO currency, never binary floats. The first increment supports IDR only; the UI labels rupiah and the API uses IDR minor units (1/100 rupiah). Approval compares integers by cross multiplication, avoiding rounding a failing margin into a passing one. The owner subsequently approved a 15% default and exact tiers at 10% and 5%. CFO/Owner can configure per-stage thresholds through the audited policy API; the supplied suggested ranges/OTIF examples are not activated as exact policies. Dispatch costs are total approved vendor estimate, including accessorials. No tax/FX engine is invented.

## Security and operational contracts

- Same-origin server sessions with HttpOnly cookies and CSRF protection; no access token in localStorage. Session fixation prevention, login throttling and revocation on logout.
- Membership role validated on every request. Standalone mode is bound to one configured tenant. Unsupported identity/deployment settings fail closed.
- UTC timestamps and explicit `Z`/offset input; user timezone is presentation only. Licence/document checks cover the proposed operation window.
- Orders cannot mutate after confirmation in CP1. Cancellation is explicit, never hard deletion. Active allocations block reuse of vehicle and driver until cancellation/execution completion is implemented.
- Idempotency keys are scoped by tenant + operation; reuse with a different payload returns conflict. Store only whitelisted order/dispatch payload fields in fingerprints.
- Audit and outbox records commit atomically with state changes. CP1 writes events but does not claim delivery to OptiNexus/OptiEntry/ERP; production dispatch relay, retry/dead-letter operations and webhook signing remain later work.
- Production requires TLS, secure session cookies, a private DB, backups and tested recovery, trusted proxy configuration, per-tenant provisioning and documented monitoring. Compose is for local development, not a Kubernetes/HA delivery claim.

## Access control (wave 1, 2026-10-24)

- Authorization checks permissions (`resource.action`, catalog in `backend/app/Domain/Access/PermissionCatalog.php`), never role names. Roles are per-tenant permission collections (`roles`, `role_permissions`, `membership_roles`).
- Each tenant has system roles named like the former hard-coded roles (viewer, dispatcher, supervisor, head_of_logistics, cfo, finance, finance_supervisor, vendor) with exactly their former access, plus `tenant_administrator`. System roles are fixed; custom roles are managed under `/api/v1/access`.
- `memberships.role` is a legacy label: it assigns the first role once (`roles_bootstrapped`) and is shown in the UI. Removing roles is never undone by it.
- Guards: grant only permissions you hold, never change your own roles, roles never cross tenants, `vendor.portal` only on vendor-bound memberships. Margin approval level = highest `dispatch.approve.levelN`; freight authority = held `freight.approve.*` for the invoice tier.
- After adding permissions run `php artisan optideli:sync-access` (adds catalog rows and tops up system roles; never removes grants).

## Unresolved integration and product contracts

OptiNexus OIDC issuer/client/claim mapping; OptiFleet availability API and staleness policy; OptiRadar event schema; OptiEntry versioned accounting event contract; EDI trading partner versions/envelopes/acknowledgements; licence verification keys/node binding; MFA; HOS jurisdiction; axle/hazmat rules; rate tax/currency policy; freight audit tolerances; emissions factors/boundaries; retention policy. These must be validated before the related production features can be accepted.

## Standards reference points

- OWASP ASVS and Authorization Cheat Sheet: server-side access checks and deny-by-default. https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- Smart Freight Centre GLEC Framework: transport-emissions methodology and factor/boundary transparency; a simple ton-km multiplier does not establish Scope 1/2/3 compliance. https://www.smartfreightcentre.org/en/our-programs/global-logistics-emissions-council/calculate-report-glec-framework/
- PostgreSQL explicit locking and constraints: correctness relies on transactions plus DB uniqueness, not UI guards. https://www.postgresql.org/docs/current/explicit-locking.html

These references guide design; no untested certification, compliance or performance improvement is asserted.

## Platform administration (wave 1B, 2026-10-24)

- OptiDeli has a local Superadmin (owner decision K1 applied to OptiDeli): `platform_*` tables hold platform
  permissions, roles, role assignments and an audit log, separate from tenant RBAC. System roles:
  `platform_administrator` (all) and `platform_support` (read only).
- Platform login is `POST /api/v1/platform/auth/login`; the session carries `platform=true` and no tenant, and
  a tenant login clears it. `PlatformContext` checks the platform permission on every `/api/v1/platform/*` call.
- The first Superadmin is created on the server with `php artisan optideli:create-platform-admin {email}`
  (password from `OPTIDELI_PLATFORM_ADMIN_PASSWORD` or a prompt). No password is ever seeded.
- In `standalone` deployment only the `OPTIDELI_STANDALONE_TENANT` slug can be created. A tenant administrator
  created by the Superadmin gets the `tenant_administrator` system role; suspending a tenant blocks its logins.
- The console lives at `/platform` in the same SPA, outside the tenant workspace.

## OptiNexus identity mode (wave 1C/1D, 2026-10-24)

- `OPTIDELI_IDENTITY_MODE=optinexus` signs tenant users in through OptiNexus (OIDC + PKCE) and takes their permissions from
  OptiNexus; local roles stay the authority only in `local` mode. The Superadmin always signs in locally. Details:
  `OPTINEXUS_ADAPTER.md`.
- Sellable modules for OptiNexus are `optideli.enterprise` and `optideli.vendor_portal`; everything else is core. This is catalog
  data (additive) and can be changed by the owner.
