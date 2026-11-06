# Customer price from transporter, vehicle and route (analysis and design proposal)

Status: **proposal, waiting for the owner's decisions** (section 7). Nothing of this is built.

## 1. The requirement (as understood)

The customer selling price shown in **Pemesanan** should be computed, not typed:

1. The user picks a **Transporter**: the tenant itself (its own fleet) or one of its vendors (dropdown: the tenant's company name and the vendors' names).
2. Then a **Vehicle**, from the vehicles of that transporter.
3. Every vehicle has its own **buying price** by *fleet type*, *origin* and *destination*.
4. The cargo details get a **unit (satuan)** dropdown; the price also depends on the unit.
5. A **mapping table** of *origin*, *destination*, *fleet type* and *unit*; each row has a **Tarif Awal** (initial rate) and a **Margin**. Tarif Awal with the margin gives the **Tarif Dasar** (base tariff), which is shown in Pemesanan and **cannot be edited** there.
6. Other costs (fuel, driver cash, overnight, others) are added **dynamically while the goods are on the way**, until delivery is complete.

## 2. What OptiDeli already does

| Need | Today |
|---|---|
| Two-sided, versioned, approved tariffs | `rate_tables` (side `SELL` or `BUY`, customer/project or vendor, validity, DRAFT, APPROVED, RETIRED, maker-checker) with `rate_lines` (lane, fleet type, service, charge code, basis, unit price, minimum). `TariffService`, import from file. |
| Lane matching | A line matches a location, a city or a zone for origin and destination; the most specific line wins (`TariffResolver`). |
| Transporter | A BUY table belongs to a vendor, or to nobody = the own fleet (`vendor_id` null). `transport_orders.vendor_id` null means the own fleet. |
| Buying price to selling price | A charge with a BUY line but no fixed SELL line is sold at BUY plus the most specific **markup rule** (PERCENT or AMOUNT), by customer, project, fleet type, service, origin city, destination city (`markup_rules`). A fixed SELL tariff always wins. |
| Pricing an SPM | At planning (`SpmService::price`) both sides are resolved and written as `spm_charges` (RATE, PROPOSED), quantities from the lines (trip, kg, CBM, carton, drop). |
| Dynamic extra costs | `SpmService::addCharge`: SELL or BUY charge with a reason, allowed from PLANNED until after the SPM is unloaded. Charge codes: loading, unloading, overnight, detention, market entry, toll, parking, driver advance. Driver cash vouchers (uang jalan) are a separate module with approval and settlement. |
| Margin control | Tenant minimum margin and approval levels on the dispatch plan. |

So the price engine exists. What differs is **where** it is used and a few **dimensions**.

## 3. Gaps

| # | Requirement | Gap |
|---|---|---|
| G1 | Price shown and fixed in **Pemesanan** | The order form still takes *Tarif dasar*, *Surcharge fuel/tol* and *Aksesorial* as typed numbers (`orders.base_price_minor`...). Pricing from tariffs happens only later, when an SPM is planned. |
| G2 | **Transporter** and **Vehicle** chosen on the order | An order has neither. The vendor lives on the SPM, the vehicle on the trip (`spm_trips`). A vehicle belongs to the own fleet or to a vendor (`vehicles.vendor_id`), so the dropdowns are possible. |
| G3 | **Unit** of the cargo | Cargo lines (`order_items`) have a quantity, weight and size but no unit. Tariff lines have a fixed list of bases (trip, kg, CBM, carton, km, day, drop), not the unit master (PCS, KG, TON, PALLET, CBM, BOX, DRUM...). |
| G4 | **One mapping row** with Tarif Awal and Margin | The margin is a separate markup rule with other dimensions (no location, no unit); the user cannot see "this lane + fleet type + unit: cost X, margin Y, base tariff Z" in one row. |
| G5 | Base tariff **not editable** in Pemesanan | The order form allows editing. |
| G6 | **Fuel** as an extra cost | Fuel (BBM) is not a charge code; toll, parking, driver advance, overnight, detention are. |
| G7 | A price **per individual vehicle** | Prices follow the *fleet type* of the vehicle (plus transporter and lane). A price that differs per plate is not modelled. |

## 4. Proposed design

### 4.1 Reuse the tariff engine; make the mapping table the BUY rate line

Keep one tariff engine instead of building a second one. A **mapping row** is a line of an approved **BUY** rate table of the transporter (vendor, or the own fleet), extended with:

* `unit_id` (nullable, the cargo unit master): the row applies to cargo in that unit; basis `UNIT` = price per unit of that kind;
* `margin_mode` (`PERCENT` in basis points or `AMOUNT`) and `margin_value`: the margin of this row;
* the **base tariff** `sell_unit_price_minor` is *computed and stored* (initial rate plus margin, rounded once) and cannot be edited by hand; it is recomputed when the row is saved.

The screen "Matriks harga" is the existing rate-line editor with the columns *Transporter*, *Asal*, *Tujuan*, *Tipe armada*, *Satuan*, *Tarif awal*, *Margin*, *Tarif dasar (otomatis)*. Version, validity, draft and approval (a second person approves) stay as they are, so a price cannot be changed silently. Import from file keeps working.

Resolution order for the selling price of a charge: **a fixed SELL tariff of the customer or project** (exists today, wins) → **the margin of the matching mapping row** (new) → **a markup rule** (exists today, remains for rows without a margin). Nothing existing breaks.

### 4.2 Pemesanan

* New fields: **Transporter** (the tenant's company name plus its vendors) and **Vehicle** (the vehicles of the chosen transporter; the fleet type comes from the vehicle).
* Each cargo line gets a **Satuan** dropdown (unit master) and its quantity.
* While the form is filled, the screen asks the resolver for a **price preview** (origin, destination, fleet type, unit, quantity) and shows *Tarif dasar* and the amount per cargo line, **read-only**.
* On saving, the order keeps a **pricing snapshot** (which table and version, which row, unit price, quantity, amount), so a later change of the matrix does not change an order that was confirmed.
* *Surcharge fuel/tol* and *Aksesorial* are removed from the order form: they become dynamic extra costs (4.3).
* If no row matches, the order cannot be confirmed: the screen says which combination has no price (decision D2).

### 4.3 Extra costs while on the way

Keep `addCharge` on the SPM (it already covers "until the goods are delivered", with reason, SELL or BUY, and verification afterwards) and add the missing charge code **FUEL (BBM)**. Driver cash stays in the driver cash module. On the order screen these appear as a read-only list of the SPM's charges, so the customer price view shows the base tariff plus what was added.

### 4.4 Flow between order and SPM

Order confirmed with transporter, vehicle and price snapshot → the SPM created from it carries the transporter and fleet type, and its planning step **uses the order's snapshot** instead of resolving again (it still resolves again if the destination or the transporter changes, as `changeDestination` does today). Trips take the vehicle chosen on the order as the default.

### 4.5 Data changes (additive)

* `rate_lines`: `unit_id`, `margin_mode`, `margin_value`, `sell_unit_price_minor`; basis `UNIT`.
* `orders`: `transporter_vendor_id` (null = own fleet), `vehicle_id`, `pricing_snapshot` (jsonb), computed `base_price_minor`.
* `order_items`: `unit_id`.
* Charge code `FUEL`.
* Existing orders keep their typed prices (a flag `price_source = MANUAL`); nothing is rewritten.

### 4.6 Permissions

Reading the mapping with the margin needs `rate.buy.view` (as buying prices today); editing `rate.manage`; approving `rate.approve` (never your own draft). Users without `rate.buy.view` see only the base tariff.

## 5. Options considered

| Option | Verdict |
|---|---|
| **A. Extend BUY rate lines with unit and margin (4.1)** | Recommended. One engine, one approval flow, one import, SPM pricing keeps working, smallest risk. |
| B. A new standalone "lane price" table beside the tariffs | Same screen as the owner describes, but two sources of truth for prices and double maintenance; the resolver and the SPM would have to know both. |
| C. Only add the transporter/vehicle/unit fields to the order and keep typing the price | Does not meet the requirement (the price must be computed and not editable). |

## 6. Effort (rough)

1. Data model, resolver with unit and margin, tests: about 1 week.
2. Matrix screen (rate-line editor extended), import: about 3 days.
3. Pemesanan: transporter, vehicle, unit, live preview, snapshot, read-only price: about 1 week.
4. Order to SPM hand-over (snapshot used at planning), fuel charge code, charges list on the order: about 3 days.
5. Hardening and a pass over billing and reports: about 3 days.

## 7. Decisions needed from the owner

* **D1. Meaning of the price per vehicle.** Is the price by *fleet type* of the vehicle (recommended: all trucks of a type cost the same on a lane), or can two vehicles of one type have different prices (needs an optional vehicle-specific row)?
* **D2. No matching row.** Block the confirmation of the order (recommended), or allow a manual price with an approval?
* **D3. Customer tariffs.** Keep the existing fixed selling tariffs per customer or project winning over the computed price (recommended, they are contracts), or remove them?
* **D4. Margin guardrail.** The tenant's minimum margin is checked when a mapping row is approved (a row below it is refused or needs the higher approval level), and the dispatch plan check stays. Agree?
* **D5. When the price is fixed.** Lock the order's price at confirmation (recommended) or recompute at SPM planning?
* **D6. Several cargo units in one order.** Price each cargo line by its own unit row and add them up (recommended); if one line has no row, the whole order is blocked (D2).
* **D7. Sellable per tenant or per customer.** Is one margin per row enough, or must margins differ per customer (today's markup rules can do that)?
