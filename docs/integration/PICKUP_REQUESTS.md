# Pickup requests from OptiStash

Status: **built**, owner decisions of 2026-11-06 applied (section 7). The OptiNexus subscription is registered when the receiver is deployed.

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
  (idempotent, like the master data receivers). The same `shipment_number` arriving again with a new event id is a separate request.
* Validation: every item needs a code, a name and a positive whole quantity; at most 500 items; strings are length-limited. A malformed event
  is answered 4xx (OptiNexus does not retry it); a tenant that is unknown or not linked is acknowledged and ignored.
* The warehouse is matched to a Deli location (`locations.type = WAREHOUSE`) by a new `external_ref` (`optistash:<code>`) that an operator
  sets (decision 2). An unknown warehouse stays unlinked until then; no location is created from an event.
* Goods: `product_code` is matched to the goods master by code; if absent the line keeps the product name as free text (the shipment line
  already supports `material` as text).

## 4. Handling (console)

New page **Permintaan jemput** (`operations.view` to read, `spm.manage` to handle; see section 7). The list filters by
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

## 7. Owner decisions (2026-11-06) and what was built

1. **Orders by default, through a dispatcher when the defaults are missing.** The tenant saves a default customer, service, destination and stage
   (`PUT /pickup-settings`, `master.manage`). With "order otomatis" on, a request from a linked warehouse becomes an order with one SPM in
   `DRAFT` when it arrives, in the name of whoever saved the defaults (an order needs a creator). Without them, or when the warehouse is not
   linked, the request waits in the inbox with the reason in `auto_failure` and a dispatcher completes it (`spm.manage`). Saving the
   defaults or linking a warehouse converts the requests that were waiting for exactly that.
2. **The warehouse is linked by an operator first.** `locations.external_ref = optistash:<CODE>`, set from the request detail
   (`POST /pickup-requests/{id}/warehouse`, `spm.manage`); a location links to one warehouse and the other way round. No location is created from an event.
3. **Goods by code with free text as the fallback.** The product code is matched to the goods master, the unit code to the units; otherwise the
   name stays as the material and the code goes into the line note.
4. **Status feedback to OptiStash is a second stage** (section 5).

No new permission: reading needs `operations.view`, handling `spm.manage`, the defaults `master.manage`. The order carries zero commercial
amounts; the SPM is priced from the tariffs when it is planned.

API: `GET /pickup-requests?status=&page=`, `GET /pickup-requests/{id}`, `POST /pickup-requests/{id}/{warehouse|convert|reject}`,
`GET|PUT /pickup-settings`. The console page is **Permintaan jemput**. Tests: `PickupRequestsTest` (signature, tenant, validation, idempotency,
linking, manual and automatic conversion, rejection, permissions, tenant isolation).
