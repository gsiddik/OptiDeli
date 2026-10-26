# OptiDeli Customer API and webhooks

Read-only access for a customer's own systems (ERP, WMS), plus notifications when something changes. A customer can see only its
own shipments and invoices. Buying prices, vendors, drivers, margins and internal reasons are never part of any response.

Staff with `customer.portal.provision` (CFO and the tenant administrator by default) manage clients and webhooks in the console:
**Integrasi pelanggan**. Customer users who sign in to the console instead get the **customer portal** (a membership bound to one
customer, role `customer`, permission `customer.portal`, created from the customer record in Master data).

## API clients

A client is a token that belongs to one customer. Scopes: `spm.read`, `invoice.read`. It can expire, be rotated (the old token
stops working immediately) or revoked, and shows when it was last used. Only a SHA-256 hash is stored; the token (`odcl_...`) is
shown once when it is issued or rotated.

```
GET /api/v1/integration/customer/spm?search=&status=      scope spm.read
GET /api/v1/integration/customer/spm/{id}                 scope spm.read
GET /api/v1/integration/customer/invoices                 scope invoice.read
GET /api/v1/integration/customer/invoices/{id}            scope invoice.read
Authorization: Bearer odcl_...
```

| Status | Meaning |
|---|---|
| 401 `API_UNAUTHENTICATED` | no token, or unknown, expired, revoked, customer deactivated |
| 403 `API_FORBIDDEN_SCOPE` | the token lacks the scope of this endpoint |
| 404 | the SPM or invoice is not this customer's (indistinguishable from not existing) |

Draft SPMs and void invoices are not visible. Amounts are minor units (x100) of the invoice currency.

## Webhooks

A subscription has a URL, a secret and the events it wants: `spm.status_changed`, `invoice.issued`. The URL must be https and must
not resolve to a private, loopback or link-local address (deployments in a closed network can set
`OPTIDELI_WEBHOOK_ALLOW_PRIVATE=true`). Redirects are not followed.

An event is written in the same transaction as the change it reports, then delivered by the scheduler container
(`php artisan optideli:webhooks:dispatch`, every minute). Delivery is at least once: use the delivery id to ignore a repeat.

```
POST <your url>
Content-Type: application/json
X-OptiDeli-Event: spm.status_changed
X-OptiDeli-Delivery: <delivery id, same as body.id>
X-OptiDeli-Timestamp: <unix seconds>
X-OptiDeli-Signature: sha256=<hex HMAC-SHA256 of "{timestamp}.{raw body}" with the subscription secret>

{ "id": "<delivery id>", "type": "spm.status_changed", "occurred_at": "...", "data": { "spm": { "id", "number", "reference", "from", "to" } } }
{ "id": "...", "type": "invoice.issued", "occurred_at": "...", "data": { "invoice": { "id", "number", "issue_date", "due_date", "currency", "amount_minor" } } }
```

Verify the signature over the raw body and reject timestamps older than a few minutes. Answer 2xx to accept. Anything else (or a
timeout of 10 s) is retried after 1 min, 5 min, 30 min, 2 h, 6 h, 12 h and 24 h; after that the delivery is `DEAD` and staff can
retry it by hand from the console. Rotating the secret takes effect at once.
