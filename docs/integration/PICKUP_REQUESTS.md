# Pickup requests from OptiStash (design)

Status: **proposal, waiting for the owner's decisions** (section 7). Nothing of this is built. Until it is, OptiDeli ignores
`optistash.pickup.requested` and no subscription is registered at OptiNexus.

## 1. What arrives

OptiStash (WMS) reports a shipment that is packed and ready for a carrier, for tenants whose transport mode is OPTIDELI. It goes through
the OptiNexus event broker like the master data events (`POST /events` there, signed webhook here).

```json
{ "event_id": "uuid", "event_type": "optistash.pickup.requested", "tenant_id": "<OptiNexus tenant id>", "occurred_at": "...",
  "payload": { "shipment_number": "SHP-000012",
    "warehouse": { "code": "WH1", "name": "Gudang Cikarang", "address": "Jl. ...", "city": "Bekasi" },
    "items": [ { "product_code": "SKU-1", "product_name": "Susu UHT", "quantity": 30, "uom": "PCS" } ] } }
```

Owner decision (2026-11-06): OptiDeli needs the warehouse as a place (never its zones, aisles, racks or bins) and the goods to collect. It
does **not** get the customer, the destination, prices or stock locations. That shapes the design: a pickup cannot become a priced
transport order by itself.

## 2. Principle

A pickup request is a **request**, not a transport order. It lands in an inbox; a dispatcher turns it into an order with a destination
(or rejects it). This is the same idea as vehicles from other platforms, which arrive as drafts and are completed by someone who knows the
missing facts. Nothing is created in the order book without a person adding what the event cannot know.

## 3. Receiving (backend)

* The existing `OptinexusEventController` (signature check, tenant from the event's OptiNexus tenant, never from the request) gets one more
  branch: `PickupRequests::apply($event)`. Unknown event types still fall through to the fleet receiver.
* Table `pickup_requests`: `id`, `tenant_id`, `source_system` (`optistash`), `source_event_id`, `shipment_number`, `warehouse` (json: code, name,
  address, city), `items` (json), `status` (`RECEIVED`, `CONVERTED`, `REJECTED`), `order_id`/`transport_order_id` (set on conversion), `reason`,
  `received_at`, `handled_by`, `handled_at`. Unique `(tenant_id, source_system, source_event_id)`: a repeated delivery changes nothing
  (idempotent, like the master data receivers). The same `shipment_number` arriving again with a new event id is shown as a duplicate warning,
  not merged.
* Validation: every item needs a code, a name and a positive whole quantity; at most 500 items; strings are length-limited. A malformed event
  is answered 4xx (OptiNexus does not retry it); a tenant that is unknown or not linked is acknowledged and ignored.
* The warehouse is matched to a Deli location (`locations.type = WAREHOUSE`) by a new `external_ref` (`optistash:<code>`). If none exists, a
  location is created from the payload, marked as coming from OptiStash and **never overwritten later** (an operator's edits win), the same
  rule as the Radar-created vehicles.
* Goods: `product_code` is matched to the goods master by code; if absent the line keeps the product name as free text (the shipment line
  already supports `material` as text).

## 4. Handling (console)

New page **Permintaan jemput** (permission `pickup.manage`, given to dispatcher and supervisor; read-only for viewers). The list filters by
status and shows shipment number, warehouse, number of lines and total quantity. Detail shows the lines and offers:

* **Buat order**: the dispatcher picks the customer, service and destination (the facts the event lacks), and optionally the carrier type
  and planned pickup. This creates the order with one SPM in `DRAFT`, origin = the warehouse location, shipment lines = the items,
  reference = the shipment number. From there the normal flow applies (pricing from tariffs, planning, trips).
* **Tolak**: with a reason (min. 5 characters). The request stays for audit.

Both actions run in one transaction with the audit event and are refused for a request that is not `RECEIVED`. Cross-tenant ids are 404.

## 5. Feedback to OptiStash (later stage, not part of the first release)

OptiStash will want to know that the goods were picked up and delivered. The natural fit is a Deli event (`optideli.pickup.status_changed`:
shipment number, `ACCEPTED`/`REJECTED`/`PICKED_UP`/`DELIVERED`, time) published through the same broker, with a subscription
`optistash <- optideli.pickup.*`. It is deliberately left out of the first release so the inbound contract can be proven first.

## 6. Registration at OptiNexus

Done on the OptiNexus side already: application `optistash`, its modules and permissions, the event `optistash.pickup.requested` in the
catalog. When the receiver ships: `ecosystem:register-event-subscription optideli "optistash.pickup.*" --url=<deli-api>/api/v1/integration/optinexus/events`
(OptiDeli has one receiver secret, so the new subscription carries the same one).

## 7. Decisions needed from the owner

1. **Inbox, not automatic order.** Is a dispatcher completing every pickup (customer, service, destination) acceptable, or should a tenant
   setting name a default customer/service/destination so that pickups become orders without a person?
2. **Warehouse as a location.** Create a Deli location for an unknown warehouse automatically (recommended), or require an operator to link it first?
3. **Goods.** Match by code and fall back to free text (recommended), or refuse a request that contains an unknown product?
4. **Feedback.** Should the first release already report status back to OptiStash (section 5), or follow in a second stage (recommended)?

## 8. Build plan once approved

1. Migration (`pickup_requests`, `locations.external_ref`), `PickupRequests` receiver with idempotency and validation, permission `pickup.manage`.
2. Controller and routes (list, detail, convert, reject), conversion through the existing order and SPM services.
3. Console page with the list, detail, convert form (searchable selects) and reject dialog.
4. Tests: signature and tenant, idempotency, validation, warehouse matching and non-overwrite, conversion, rejection, permissions, tenant isolation.
5. Register the subscription at OptiNexus and verify end to end with an OptiStash shipment (needs the OptiStash tenant linked to OptiNexus).
