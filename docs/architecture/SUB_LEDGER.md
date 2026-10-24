# OptiDeli sub-ledger (wave 5, decision K2)

OptiDeli records only what it must to run freight: **customer invoices** (receivables), **vendor bills** (payables) and **payments**. It has no
chart of accounts, journals or general ledger; those belong to OptiEntry, which reads this data through the
[Opti Integration Feed v1](../integration/OPTI_INTEGRATION_FEED_V1.md).

## Model (`2026_10_24_000005_create_subledger_and_integration_feed`)

- `ledger_documents`: one row per customer invoice or vendor bill. Money is `bigint` minor units. Status `OPEN` -> `PARTIAL` -> `PAID`, or `VOID`
  (only when unpaid). PostgreSQL partial unique indexes allow one live customer invoice per order and one live vendor bill per freight invoice.
- `ledger_payments`: append-only (trigger). Unique per `(tenant, document, reference)`, so replaying a payment cannot double-count.
- `ledger_counters`: gap-free numbers `AR-000001` / `AP-000001` per tenant, assigned under a row lock.
- `integration_feed_*`: gap-free per-tenant event sequence, append-only events, hashed read credentials.

## API (all need `finance.access`, tenant from the session)

`GET /ledger/documents` (filters `kind`, `status`, `overdue`), `GET /ledger/documents/{id}`, `GET /ledger/summary` (open balances by kind and currency),
`POST /ledger/customer-invoices {order_id}` (order must be DELIVERED), `POST /ledger/vendor-bills {freight_invoice_id}` (invoice must be
READY_FOR_PAYMENT), `POST /ledger/documents/{id}/payments`, `POST /ledger/documents/{id}/void`. Creating a document twice returns the live one (200).

Every mutation locks its document/order/invoice row and writes its feed event in the same transaction. A UI is not part of this increment.
