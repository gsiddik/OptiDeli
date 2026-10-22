# Increment 2A — execution, yard, ePOD and freight audit

Baseline: merged main `4d1cdf4697b460dc368cf5cbc878689484cfe0ec` (PR #1). Routes below are under `/api/v1`; existing cookie session, CSRF and tenant membership authority apply. Vendor sessions cannot access internal routes. All monetary API fields are integer minor units (100 = Rp1); use exact integer comparisons.

## Execution and evidence

- `GET trips`, `GET trips/{id}`: tenant-scoped list/detail with order, plan, legs, POD and claims.
- `POST trips/{id}/vendor`: vendor_id + contract_reference; only ASSIGNED and before a live invoice exists.
- `POST trips/{id}/legs`: origin, destination, planned_eta with explicit timezone. Legs must connect and ETA must increase; editable only before departure.
- `POST trips/{id}/transition`: action start/complete/cancel; cancel requires reason and ASSIGNED. Start rechecks resource readiness. Complete requires all legs ARRIVED, verified full DELIVERED ePOD with intact private file and no vehicle still in yard.
- `POST legs/{id}/transition`: depart/arrive, in sequence.
- `POST trips/{id}/pods`: multipart receiver_name, delivered_units, outcome DELIVERED/EXCEPTION, note and file. PDF/JPEG/PNG ≤10 MB. Idempotency-Key required.
- `POST pods/{id}/verify`: decision verify/reject + note; independent Supervisor/Head/CFO. Partial/exception evidence cannot release the trip through the complete transition.
- `GET files/pods/{id}`, `GET files/invoices/{id}`: authenticated private attachments; vendor identity must own the record. Files are outside public storage, UUID-named, MIME-validated and hashed. Production requires storage retention, malware scanning and backup policies before real documents are accepted.

COMPLETED/CANCELLED trip histories remain. Partial unique constraints enforce one active allocation per order, vehicle and driver, allowing safe reuse after release. Cancelled predeparture orders return to CONFIRMED for a fresh dispatch plan.

## Yard

`GET yard`, `POST yard/docks` (hub/name), `POST yard/appointments` (dock_id, trip_id, starts_at, ends_at; Idempotency-Key), `POST yard/appointments/{id}/transition`.

BOOKED → GATE_IN → AT_DOCK → COMPLETED → GATE_OUT; BOOKED can cancel. Half-open intervals allow adjacent slots; overlapping reservations are rejected under locks. Actual dock occupancy is checked independently of scheduled windows. Timestamps are server-recorded. Yard list currently returns the latest 100 reservations; no sensor/WMS feed or automatic detention billing.

## Vendor portal and invoice correction

Internal: `GET/POST vendors`; `POST vendors/{id}/account` (CFO only, new local user, strong password). Vendor: `GET vendor/portal`, `POST vendor/invoices`, `POST vendor/invoices/{correction}/correct`, `POST vendor/claims`, `POST vendor/disputes/{id}/action` (respond + note).

Invoice capture is multipart: trip_id, number, base_minor, accessorial_minor, signed rounding_minor, document_reference, file; Idempotency-Key required. Only one live invoice per trip and live vendor/number. A correction uploads a new private document, retains the prior VOID invoice, and resolves its automatic base-rate correction request. Correction is allowed only for BASE_RATE_MISMATCH/UNVERIFIED_ACCESSORIAL, must match contracted base and cannot bypass an unresolved manual dispute.

Portal publishes internal dispute messages and linked ePOD access only to the relevant vendor. No email, external vendor message service, accounting posting or bank payment is sent.

## Finance controls confirmed by owner

| Condition | Result / authority |
| --- | --- |
| Any nonzero base-rate difference, including Rp1 | Auto-hold and auto-dispute; vendor correction mandatory; no discretionary waiver |
| Pending/unverified accessorial or unmatched verified claim total | Auto-hold; independent Finance evidence review and invoice correction where needed |
| Missing complete trip / verified intact ePOD / invoice file | Auto-hold until evidence is complete |
| Exact match, margin ≥5%, no dispute | Ready for payment automatically |
| Only rounding/admin/toll, absolute difference ≤Rp10,000 | Independent Finance/Finance Supervisor approval |
| Valid accessorial or other moderate discrepancy, exposure ≤Rp2,000,000 inclusive | Two distinct approvers: Finance Supervisor + Logistics Manager (Supervisor/Head role) |
| Exposure >Rp2,000,000 OR actual margin <5% | CFO approval and dispute resolution |

Exposure = abs(invoiced accessorial − originally approved accessorial) + abs(rounding). Negative rounding cannot offset a large claim to lower authority. Non-admin accessorial remains dual approval even below Rp10,000; it cannot be labelled rounding to bypass verified claim matching.

`GET freight/invoices?status=ON_HOLD|READY_FOR_PAYMENT|VOID`, `GET freight/invoices/{id}`, `POST freight/invoices` and `POST freight/invoices/{correction}/correct` (internal Finance), `POST freight/invoices/{id}/approve` (version + note), `POST freight/invoices/{id}/disputes` (message), `POST freight/disputes/{id}/action` (resolve + note).

Claims: `POST freight/claims` (trip_id, category, amount_minor, reason, evidence_reference, optional appointment_id; Idempotency-Key), `POST freight/claims/{id}/verify` (decision verify/reject + note). Finance cannot verify its own claim or approve its own invoice. New/changed claims increment invoice evidence version, re-hold settlement and invalidate old approvals. Approvals are unique by actor and authority per invoice version. Stale approval version returns conflict. Open disputes block readiness; CFO-tier disputes require CFO resolution.

All mutations retain atomic audit/outbox records. Outbox delivery remains a separate integration increment. Tenant-wide write serialization is conservative and has not been benchmarked for enterprise throughput.

## Follow-on boundaries

Rate cards/tender automation, multi-order consolidation, postdeparture cancellation/exception resolution, accounting settlement execution, real identity/licensing/telematics/EDI, production scanning/retention/HA and optimization remain outside increment 2A. Do not infer live vendor contract validation from a manually entered PO reference.
