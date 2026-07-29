# Dynamic Pricer User Guide

## Overview

Dynamic Pricer is an airline offer and rules engine. Inventory controls whether seats can be sold, bundles define default services, rules adjust prices and services, and checkout purchases immutable offers.

Public customers see five THY-style packages when available:

- EcoFly
- ExtraFly
- PrimeFly
- BusinessFly
- BusinessPrime

Admins configure the catalog, fares, services, constraints, pricing rules, inventory, and THY MCP imports from Filament.

## Admin Setup

1. Sign in to `/admin` with an admin account.
2. Configure airports under `Operations > Airports`. Each airport page also shows origin and destination flights.
3. Configure cabins under `Catalog > Cabins`. Add booking classes directly inside each cabin.
4. Configure products under `Catalog > Products`. Add bundles directly inside each product.
5. Configure bundles under `Catalog > Bundles`. Add included services and bundle-specific service prices from the bundle page.
6. Configure services under `Catalog > Services`. Add service prices and service constraints from the service page.
7. Create flights under `Operations > Flights`. Add inventory and base fares directly from the flight page.
8. Add pricing rules under `Pricing > Pricing Rules`. Rule execution logs are shown on each rule page.
9. Review offers, import batches, orders, tickets, and user order history from their parent records.

The admin sidebar intentionally shows top-level concepts. Related setup records such as booking classes, bundle services, service prices, constraints, inventory, base fares, and tickets are configured inside their parent pages.

## THY MCP Imports

Set these environment values before running live imports:

```env
THY_MCP_URL=
THY_MCP_PYTHON=python3
```

Use `Operations > Price Import Batches`, then choose `Search THY MCP`. Open an import batch afterward to inspect its imported items and raw mapping audit.

The import action stores:

- Search parameters
- MCP configuration status
- Raw response snapshot
- Preview import items when returned by the gateway

The app uses local imported fares for public search and checkout. Public customers do not call THY MCP live.

## Bundles And Services

Services are first-class building blocks. Do not add new database columns for baggage, seats, lounge access, refundability, or similar features. Add a service instead.

Recommended service examples:

- `CHECKED_BAG`
- `CABIN_BAG`
- `SEAT_SELECTION`
- `CHANGE_ALLOWED`
- `CHANGE_FEE`
- `REFUNDABLE`
- `REFUND_FEE`
- `LOUNGE`
- `FAST_TRACK`
- `PRIORITY_BOARDING`
- `WIFI`
- `MEAL`

Bundle services define what each package includes. Open a bundle and use `Add all active services` to add the whole service catalog to that package, then edit each row to mark whether it is included and what value it carries.

For change and refund rules, the right service controls whether the action is possible at all:

- `CHANGE_ALLOWED=false` means no change is allowed. `CHANGE_FEE` should be empty because no fee applies.
- `REFUNDABLE=false` means no refund is allowed. `REFUND_FEE` should be empty because no fee applies.
- Fee services are only displayed when the matching right is available.

Bundle pages also include package-specific service prices. Use these for prices that depend on the package. Open a service when you want to configure default service prices or constraints across all packages.

## Service Constraints

Use constraints to prevent nonsensical custom bundles.

Constraint types:

- `requires`: selected service requires another service
- `conflicts`: selected service cannot be combined with another service
- `min_quantity`: selected service has a minimum quantity
- `max_quantity`: selected service has a maximum quantity

Example: checked baggage can require cabin baggage and can be limited to three bags.

## Pricing Rules

Rules are evaluated by priority. Active matching rules modify the offer snapshot.

Condition examples:

```text
route == "IST-LHR"
trip_type == "round_trip"
route_count_12m >= 10
loyalty_tier == "elite" || loyalty_tier == "elite_plus"
contains("fri,sat,sun", departure_day)
```

Supported actions:

- Percentage discount
- Percentage surcharge
- Fixed discount
- Fixed fare
- Include service

Useful presets:

- Weekend uplift
- Route surcharge
- Repeat-route discount
- Loyalty service
- Roundtrip discount
- Corporate fixed fare

## Public Search

Customers search from the homepage. The app creates expiring immutable offers for matching flights and packages.

For one-way trips, customers select one offer.

For round trips, customers select one outbound offer and one return offer. Each leg can have a different package.

## Checkout

Checkout submits offer IDs, not fare IDs or inventory IDs.

During checkout the app:

1. Loads the immutable offers.
2. Rejects expired offers.
3. Locks inventory.
4. Rejects oversold fares.
5. Creates an order.
6. Issues tickets and ticket segments.

Signed-in customers can save a passport number in their profile. Checkout pre-fills account data when available.

## Wingo Custom Bundle Builder

Open Wingo from the public site. The custom bundle panel lets customers choose:

- Route
- Date
- Checked bag
- Seat selection
- Change right
- Refund right
- Lounge
- Fast track

Wingo sends those needs to the pricing engine and recommends valid packages. The same rules and constraints used by public search are used by Wingo.

## Troubleshooting

No fares appear:

- Check the flight date and route.
- Confirm inventory exists for the flight and booking class.
- Confirm base fares are active and valid.
- Confirm the bundle is public and active.

Checkout fails:

- The offer may have expired.
- Inventory may have changed.
- Roundtrip checkout may be missing one leg.

THY import says not configured:

- Set `THY_MCP_URL`.
- Confirm MCP authentication is available for the configured bridge.

Rule does not apply:

- Confirm the rule is active.
- Confirm the expression matches the offer context.
- Check priority and stackability.
- Check spelling for route, bundle code, class letters, and loyalty tier.
