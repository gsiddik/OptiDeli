# Vendor portal: fleet, rates and seats

## Who signs in
Staff sign in through OptiNexus. A vendor's (or a customer's) users do not exist in OptiNexus: the tenant creates their accounts in OptiDeli
(**Vendor & portal → Akun**) and they sign in with their own password at the same address, from **Masuk sebagai vendor atau pelanggan**
(`POST /api/v1/auth/external-login`, tenant code + email + password). That door refuses anyone who is not bound to a vendor or a customer. Their
roles are local (`vendor.portal`); nothing about them is asked from OptiNexus. In local identity mode the ordinary sign-in serves everyone.

## Seats
A seat is a person who can sign in to run the tenant's operations: its own staff and its vendors' users. Customers' portal users take none.
OptiDeli counts them (a daily peak, `seat_snapshots`), shows the figures on **Vendor & portal** and reports each finished month to OptiNexus as
usage of the `user_seat` meter (`optideli:seats:report`, daily; the month's peak; idempotency key `optideli-seats-YYYY-MM`).

The subscription's `user_limit` is what is **included**: it never stops anyone being added. What is above it is billed by OptiNexus when the price
that bills `user_seat` has an included quantity. Reporting needs the OptiDeli service account to hold the scope `usage.write`; until it does, the
command records the month as FAILED and tries again on each run. Switching a vendor account off frees its seat.

## The vendor's fleet
In the portal a vendor registers **vehicles** (plate, vehicle type, capacity, box size) and **drivers** (name, SIM number, phone) and uploads their
documents: STNK and KIR for a vehicle, SIM and KTP for a driver (PDF, JPG or PNG up to 5 MB; STNK, KIR and SIM carry a valid-until date). New and
changed rows wait (**PENDING**). The tenant (`vendor.fleet.verify`) opens **Verifikasi armada vendor**, looks at the documents and verifies or
rejects with a reason. Verifying sets what the dispatch already checks: a vehicle's documents hold until the earlier of STNK and KIR, a driver's
licence and eligibility run to the SIM's date. A changed vehicle, a new document or a changed SIM number puts the row back in the queue.

## The vendor's rates
A rate is per **vehicle type**, never per driver. Every line of a vendor's rate names the vehicle type. Two ways in:
- the vendor proposes it in the portal (a draft BUY tariff of its own, then **Ajukan ke tenant**); the tenant approves it or returns it with a
  reason, and does not edit it;
- the tenant enters it for the vendor in **Tarif** (BUY, with the vendor) like any other tariff; once approved it shows to the vendor read-only.

Approval in the system is the usual maker-checker (someone other than the author approves); the price itself is taken to have been agreed outside
the system. Nothing is a buying price before it is approved.

## Assigning an SPM to a vendor's vehicle
Planning a trip with a vendor's vehicle requires that vendor's own, verified and active driver, gives the SPM to that vendor (an SPM of another
vendor is refused) and prices the SPM's buying side again from the vendor's approved rate for the vehicle's type, on the SPM's lane. When the vendor
has no approved rate for that type on that lane the planner says so and leaves the buying side as it was. Buying charges already verified are never
priced again. The vendor sees the trips assigned to it, with the vehicle, driver, schedule and what it will be paid, never the selling side.
