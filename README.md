# WingMate ✈️

**WingMate is a dynamic airline offer and pricing platform built around revenue management, inventory-aware pricing, flexible bundles, and personalized passenger offers.**

The project explores how traditional fixed fare packages can evolve into a more dynamic offer experience where availability, pricing rules, passenger context, and ancillary services are evaluated together before an offer is generated.

> Built collaboratively as an internship project to model real airline pricing, bundling, inventory, and offer-management scenarios in a working web application.

## What WingMate Does

WingMate brings the main pieces of an airline offer flow into one system:

- **Flight & inventory management** — routes, dates, booking classes, seat availability, and base fares
- **Dynamic bundles** — configurable fare packages and included services
- **Ancillary services** — baggage, seat selection, lounge, fast track, Wi-Fi, meals, change/refund rights, and more
- **Pricing rules** — route, trip type, loyalty, travel history, departure day, and similar conditions can influence the final offer
- **Offer engine** — generates immutable, inventory-aware offers for passengers
- **Custom bundle recommendations** — passengers can express what they need and receive valid package recommendations
- **Checkout & ticketing flow** — offer validation, inventory locking, order creation, and ticket issuance
- **Admin panel** — manage flights, fares, bundles, services, constraints, pricing rules, inventory, imports, and orders

## Offer Flow

```text
Flight & Inventory
        ↓
Fare / Booking Class
        ↓
Services & Bundles
        ↓
Pricing Rules
        ↓
Offer Engine
        ↓
Personalized Offer
        ↓
Checkout & Order
```

## Example Pricing Logic

The rule engine can evaluate conditions such as:

```text
route == "IST-LHR"
trip_type == "round_trip"
route_count_12m >= 10
loyalty_tier == "elite"
departure_day == "fri"
```

and apply actions such as:

- percentage discount or surcharge
- fixed discount
- fixed fare
- service inclusion

This makes it possible to model scenarios such as weekend uplifts, route-based pricing, loyalty benefits, repeat-route discounts, and round-trip incentives.

## Service Constraints

Custom offers can also respect service relationships such as:

- `requires` — one service requires another
- `conflicts` — two services cannot be selected together
- `min_quantity`
- `max_quantity`

This helps prevent invalid or unrealistic bundle combinations.

## Tech Stack

**Backend**
- Laravel 13
- PHP 8.3+
- Filament 5
- Laravel Fortify / Reverb

**Frontend**
- React 19
- TypeScript
- Inertia.js
- Tailwind CSS 4
- Vite

**Quality & Testing**
- Pest
- Larastan / PHPStan
- ESLint
- Prettier

## Getting Started

```bash
git clone https://github.com/yigitkerem/WingMate.git
cd WingMate
composer setup
composer dev
```

For environment configuration, copy `.env.example` to `.env` if it is not created automatically and configure the required database and service values.

## Documentation

A detailed usage guide covering admin setup, pricing rules, service constraints, public search, checkout, imports, and troubleshooting is available in [`USER_GUIDE.md`](./USER_GUIDE.md).

## Project Focus

WingMate is not only a booking interface. The core focus is the **decision layer behind airline offers** — how inventory, pricing, passenger context, bundle design, and business rules can be combined to generate more relevant and commercially meaningful offers.

The project was developed as a collaborative learning and internship experience around airline revenue management, dynamic pricing, business analysis, and offer management.
