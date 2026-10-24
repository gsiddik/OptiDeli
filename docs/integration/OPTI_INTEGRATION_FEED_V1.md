# Opti Integration Feed v1

Status: **contract with the owner's answers of 2026-10-24 applied** (pairing, tax, reversal). Waiting for the owner's explicit go before the OptiEntry adapter. No consumer is built until the owner approves this document.

A producer application (first: OptiDeli) publishes its **financial facts** as an ordered, immutable, per-tenant event stream. An accounting
system (first: OptiEntry) **pulls** the stream and turns the facts into journals with its own Posting Engine. Producers send business facts
(documents, amounts, parties, dates), never accounts, debits or credits. Decision K2: OptiDeli keeps a sub-ledger only; the general ledger
belongs to OptiEntry. Notifications are a different channel (the OptiNexus event broker, decision K4); financial facts never travel by webhook.

## Why a pull feed

The consumer controls its pace and can replay from any sequence after an outage; the producer needs no knowledge of the consumer; a lost
delivery is impossible because the consumer states the last sequence it has stored (`after`).

## Endpoint

`GET /api/v1/integration/feed?after=<sequence>&limit=<1..500>` (defaults `after=0`, `limit=100`). Rate limit 60 requests/minute per caller.

Authentication: `Authorization: Bearer odfd_...`. A credential is bound to exactly one tenant of the producer and has scope `feed.read`.
The tenant is taken from the credential only; a tenant id in the request is ignored. The producer stores a SHA-256 hash; the token is
shown once at issue time. Issue: `php artisan optideli:feed:issue-credential <tenant> --consumer=optientry --output=<file>`; revoke:
`optideli:feed:revoke-credential <id>`. Errors: `401 UNAUTHENTICATED | INVALID_CREDENTIAL` (missing, unknown, expired, revoked),
`403 TENANT_INACTIVE`, `422` for bad query values.

**Pairing (mandatory in SaaS mode, owner decision).** While `OPTIDELI_IDENTITY_MODE=optinexus` (or `OPTINEXUS_FEED_PAIRING=required`) a valid credential is
not enough. The credential records the OptiNexus tenant it was issued for; on every read the tenant must still be linked to that tenant and
OptiNexus (`GET /tenants/{id}/commercial-context`, scope `commercial.read`, cached 300 s) must say: tenant `ACTIVE`, a live subscription
(`ACTIVE`, `TRIAL`, `GRACE_PERIOD`, `PAST_DUE`), and both `optideli` and the accounting application (`OPTINEXUS_FEED_CONSUMER_APPLICATION`,
default `optiaccounting`) entitled. Refusals, 403: `FEED_CREDENTIAL_UNPAIRED`, `FEED_PAIRING_CHANGED`, `FEED_TENANT_NOT_ACTIVE`,
`FEED_SUBSCRIPTION_INACTIVE`, `FEED_PRODUCER_NOT_ENTITLED`, `FEED_CONSUMER_NOT_ENTITLED`. OptiNexus unreachable: `503 FEED_GOVERNANCE_UNAVAILABLE`
with `Retry-After`; the consumer keeps its cursor, nothing stale is served. Only reading is gated; OptiDeli keeps recording documents.
`optideli:feed:issue-credential` refuses an unlinked tenant when pairing is required. Standalone installations (no OptiNexus) stay credential-only.
The OptiDeli service account in OptiNexus needs scope `commercial.read` in addition to `authorization.check`.

Response:

```json
{
  "data": [ { "event_id": "uuid", "sequence": 41, "event_type": "optideli.payment.recorded", "schema_version": "1.0",
              "source_system": "optideli", "tenant_id": "<OptiNexus tenant id, else producer tenant id>",
              "occurred_at": "2026-10-24T09:15:00.123Z", "correlation_id": "<document id>",
              "source": { "type": "ledger_payment", "id": "uuid", "number": "TRF-1" },
              "dimensions": { "order_id": "uuid", "trip_id": "uuid|null", "vendor_id": "uuid|null", "freight_invoice_id": "uuid|null" },
              "payload": { } } ],
  "next_cursor": 41, "has_more": false, "high_water": 41
}
```

`next_cursor` is the last sequence in the page (or `after` for an empty page): store it and send it as the next `after`.
`high_water` is the producer's latest sequence for the tenant; `high_water == next_cursor` means the consumer is caught up.

## Guarantees

- **Gap-free, per tenant.** Sequences are 1, 2, 3, ... with no holes, assigned under a row lock in the same database transaction as the
  business change. A rolled-back change consumes no number, so a consumer may treat a hole as a fault and stop.
- **Commit order.** Event N+1 was committed after event N.
- **Immutable.** An event is never changed or deleted (a database trigger enforces this on PostgreSQL). A correction is a new event.
- **Idempotent consumption.** `(source_system, event_id)` is unique; applying the same event twice must change nothing.
- **Money** is a decimal string with the currency's minor-unit exponent (`"1600000.00"` for IDR, `"1500"` for JPY). Never a float.
- **Dates** `issue_date`, `due_date`, `paid_on` are `YYYY-MM-DD` document dates; `occurred_at` is UTC and is not an accounting date.
- **Versioning.** `schema_version` is `major.minor`. Adding payload fields is minor; a consumer ignores unknown fields. A breaking change
  is a new major version served next to the old one.
- **No history back-post.** The feed starts at sequence 1 on the day the sub-ledger is used; OptiEntry decides the cutover date and an
  opening balance itself.

## Event types (v1.0)

Common payload fields for documents: `document_kind` (`CUSTOMER_INVOICE` | `VENDOR_BILL`), `currency`, `amount`, `issue_date`, `due_date`,
`counterparty_name`.

| `event_type` | Fact | `source` | Extra payload |
|---|---|---|---|
| `optideli.customer_invoice.issued` | Receivable created for a delivered order (document `AR-000001`...) | `ledger_document` + number | `customer_name`, `order_number` |
| `optideli.vendor_bill.recorded` | Payable recorded from a freight invoice that passed audit (`AP-000001`...) | `ledger_document` + number | `vendor_code`, `vendor_name`, `freight_invoice_number`, `order_number` |
| `optideli.payment.recorded` | Money received (`RECEIPT`) or paid out (`DISBURSEMENT`) against a document | `ledger_payment` + payment reference | `direction`, `document_kind`, `document_number`, `currency`, `amount`, `paid_on`, `method`, `reference`, `outstanding_after`, `document_status` |
| `optideli.document.voided` | An unpaid document was cancelled | `ledger_document` + number | `document_kind`, `currency`, `amount`, `reason` |

Rules the producer enforces, so the consumer can rely on them: a document exists once per order or freight invoice while live; payments never
exceed the outstanding amount; a document with payments cannot be voided (the payment must be reversed in the accounting system first;
OptiDeli has no payment reversal in v1); document numbers are gap-free per tenant and kind.

## What the consumer must do

1. Hold one feed credential per producer tenant, stored as a secret.
2. Poll `after=<last stored sequence>` until `has_more` is false; store events and the new cursor in **one** transaction.
3. Reject a page whose first sequence is not `cursor + 1` (gap) and alert; never skip.
4. Map facts to accounts only through its own posting rules (for example an `optideli.customer_invoice.issued` posts to the receivable and
   revenue roles of the mapped legal entity). Dimensions (`order_id`, `trip_id`, `vendor_id`) become analytical dimensions.
5. Keep the producer's `document_number` and `event_id` on every journal it creates, for audit and reconciliation.

## Decisions and roadmap (owner, 2026-10-24)

- **Pairing:** mandatory in SaaS mode (above).
- **Tax (PPN):** never computed in OptiDeli. v1 carries gross amounts; OptiEntry's GL/Tax Engine derives tax from its own tax codes and rules.
- **Payment reversal is not in v1.** Immutability stays: a payment or event is never edited or deleted, a `PAID` document is never rewritten.
  v1 compensation: for a customer (AR) overpayment or cancelled order, OptiEntry books a customer deposit / unearned revenue or a credit
  note against the next invoice; for a vendor (AP) claim after payment, the claim becomes a penalty deduction on the vendor's next trip
  bill through Freight Audit / Waterfall Tendering (Accessorial & Dispute module).
- **v1.1 (additive, minor version):** new event types `optideli.payment.reversed` (reason: returned goods, double transfer, cargo dispute)
  and `optideli.credit_note.issued`, from which OptiEntry creates reversing entries. Existing v1 events and consumers are unaffected.
