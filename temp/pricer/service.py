"""Pricer service layer: search and purchase.

These functions are called by both the web UI and the AI agent tools.
Prices, rules and availability ALWAYS originate here (the source of truth);
the LLM never generates them.
"""

from __future__ import annotations

from datetime import datetime

from . import domain


def list_airports() -> list[dict]:
    conn = domain.connect()
    rows = conn.execute("SELECT id, code, name FROM airports ORDER BY code").fetchall()
    conn.close()
    return [dict(r) for r in rows]


def _airport_id(conn, code: str) -> int | None:
    row = conn.execute(
        "SELECT id FROM airports WHERE code = ? COLLATE NOCASE", (code.strip(),)
    ).fetchone()
    return row["id"] if row else None


def _fare_dict(row, seat_passengers: int) -> dict:
    d = dict(row)
    d["available"] = d["count_available"] >= seat_passengers
    # A right exists only when a deadline is defined; a zero fee just means "free"
    d["changeable"] = d["latest_change_hours"] is not None
    d["refundable"] = d["latest_refund_hours"] is not None
    d["free_change"] = d["changeable"] and d["change_fee_usd"] == 0
    d["free_refund"] = d["refundable"] and d["refund_fee_usd"] == 0
    return d


def search_flights(
    origin: str,
    destination: str,
    date: str,
    adults: int = 1,
    children: int = 0,
    babies: int = 0,
    trip_type: str = "one_way",
    changeable_only: bool = False,
) -> dict:
    """Return flights and fares for a route and date (basic mode: A/B/C).

    seat_passengers = adults + children (infants occupy no seat).
    When changeable_only is True, only changeable fares are returned.
    """
    conn = domain.connect()
    fare_type = "round_trip" if trip_type == "round_trip" else "one_way"
    seat_passengers = adults + children

    origin_id = _airport_id(conn, origin)
    dest_id = _airport_id(conn, destination)
    if origin_id is None or dest_id is None:
        conn.close()
        return {
            "error": "unknown_airport",
            "message": f"Unknown airport code: {origin!r} or {destination!r}.",
            "known_airports": [a["code"] for a in list_airports()],
        }

    flights = conn.execute(
        "SELECT id, date, hour, flight_number, plane_model FROM flights"
        " WHERE origin_id = ? AND dest_id = ? AND date = ? ORDER BY hour",
        (origin_id, dest_id, date),
    ).fetchall()

    results: list[dict] = []
    for f in flights:
        avails = conn.execute(
            "SELECT * FROM availabilities WHERE flight_id = ? AND fare_type = ?"
            " ORDER BY base_price_usd",
            (f["id"], fare_type),
        ).fetchall()

        fares_by_class: dict[str, dict] = {}
        for letter in domain.CLASS_TEMPLATES:
            match = None
            for a in avails:
                base_letter = a["class_letters"].replace("(R)", "")
                if base_letter == letter and a["count_available"] >= seat_passengers:
                    match = a
                    break
            if match is not None:
                fare = _fare_dict(match, seat_passengers)
                if changeable_only and not fare["changeable"]:
                    continue
                fare["price_breakdown"] = domain.price_breakdown(
                    match["base_price_usd"], adults, children, babies
                )
                fares_by_class[letter] = fare

        if fares_by_class:
            dep_hour = f["hour"][:5]
            try:
                hp, mp = map(int, dep_hour.split(":"))
                arr_m_tot = hp * 60 + mp + 210
                arr_hour = f"{(arr_m_tot // 60) % 24:02d}:{arr_m_tot % 60:02d}"
            except Exception:
                arr_hour = "11:05"

            city_map = {
                "IST": "İstanbul", "SAW": "İstanbul", "ESB": "Ankara", "ADB": "İzmir",
                "AYT": "Antalya", "LHR": "Londra", "LGW": "Londra", "CDG": "Paris",
                "AMS": "Amsterdam", "FRA": "Frankfurt", "MUC": "Münih", "FCO": "Roma",
                "MAD": "Madrid", "BCN": "Barselona", "JFK": "New York", "DXB": "Dubai",
                "VIE": "Viyana", "ZRH": "Zürih", "TRN": "Torino"
            }

            results.append(
                {
                    "flight_id": f["id"],
                    "flight_number": f["flight_number"],
                    "plane_model": f["plane_model"],
                    "date": f["date"],
                    "hour": dep_hour,
                    "arrival_hour": arr_hour,
                    "duration_str": "3s 30d",
                    "origin": origin.upper(),
                    "origin_city": city_map.get(origin.upper(), origin.upper()),
                    "destination": destination.upper(),
                    "dest_city": city_map.get(destination.upper(), destination.upper()),
                    "fare_type": fare_type,
                    "fares": fares_by_class,
                }
            )

    conn.close()
    return {
        "origin": origin.upper(),
        "destination": destination.upper(),
        "date": date,
        "trip_type": fare_type,
        "passengers": {"adults": adults, "children": children, "babies": babies},
        "seat_passengers": seat_passengers,
        "flight_count": len(results),
        "flights": results,
    }


# ---------------------------------------------------------------------------
# Dynamic bundling: ancillary catalogue plus a deterministic bundle builder.
# The AI never invents products or prices; it only explains this output.
# ---------------------------------------------------------------------------

# Catalogue of sellable ancillaries (USD per person). In a real system this
# would come from an Ancillary service / Offer Engine; here it is deterministic.
ANCILLARY_CATALOG: dict[str, dict] = {
    "bag_23kg": {"name_tr": "23 kg ek bagaj", "name_en": "23 kg checked bag", "price_usd": 40},
    "seat_standard": {"name_tr": "Standart koltuk seçimi", "name_en": "Standard seat selection", "price_usd": 15},
    "seat_aisle": {"name_tr": "Koridor koltuğu", "name_en": "Aisle seat", "price_usd": 25},
    "priority_boarding": {"name_tr": "Öncelikli biniş", "name_en": "Priority boarding", "price_usd": 10},
    "lounge": {"name_tr": "Lounge erişimi", "name_en": "Lounge access", "price_usd": 35},
    "meal": {"name_tr": "Özel yemek", "name_en": "Special meal", "price_usd": 12},
}


def _bundle_from(fare: dict, extras: list[str], adults: int, children: int, babies: int,
                 label_tr: str, label_en: str, discount_pct: float = 0.0) -> dict:
    """Build a single bundle from a real fare plus real ancillaries."""
    seat_passengers = adults + children
    included = []
    if fare["checked_baggage_kg"] > 0:
        included.append({"key": "bag_included", "name_tr": f"{fare['checked_baggage_kg']} kg bagaj dahil",
                         "name_en": f"{fare['checked_baggage_kg']} kg baggage included"})
    if fare["seat_selection_free"]:
        included.append({"key": "seat_included", "name_tr": "Ücretsiz koltuk seçimi dahil",
                         "name_en": "Free seat selection included"})
    if fare["changeable"]:
        included.append({"key": "change_right", "name_tr": "Değişiklik hakkı",
                         "name_en": "Change right"})

    # Never re-sell a service the fare already includes (business rule)
    chosen: list[dict] = []
    for key in extras:
        if key.startswith("seat_") and fare["seat_selection_free"]:
            continue
        item = ANCILLARY_CATALOG[key]
        chosen.append({"key": key, **item})

    extras_pp = sum(i["price_usd"] for i in chosen)
    extras_total = extras_pp * seat_passengers
    fare_total = fare["price_breakdown"]["grand_total_usd"]
    
    # Apply the bundle discount
    discount_amount = extras_total * discount_pct
    bundle_total = fare_total + extras_total - discount_amount

    return {
        "label_tr": label_tr, "label_en": label_en,
        "fare_class": fare["class"],
        "availability_id": fare["id"],
        "changeable": fare["changeable"],
        "included": included,
        "extras": chosen,
        "extras_per_person_usd": extras_pp,
        "extras_total_usd": extras_total,
        "fare_family_total_usd": fare_total,
        "discount_amount_usd": round(discount_amount, 2),
        "bundle_total_usd": round(bundle_total, 2),
        "original_total_usd": round(fare_total + extras_total, 2),
        "price_breakdown": fare["price_breakdown"],
    }


def build_bundles(origin: str, destination: str, date: str, adults: int = 1,
                  children: int = 0, babies: int = 0, trip_type: str = "one_way") -> dict:
    """Build three personalised bundles for a flight: Economy / Recommended / Comfort.

    Tum bilesenler gercek satilabilir urunlerdir (mevcut fare + ancillary katalogu).
    """
    res = search_flights(origin, destination, date, adults, children, babies, trip_type)
    if res.get("error") or not res.get("flights"):
        return res if res.get("error") else {"error": "no_flights",
                                             "message": "Bu tarih/rota icin ucus bulunamadi."}

    # En cok secenegi olan ilk ucusu al (MVP: ilk ucus)
    flight = res["flights"][0]
    fares = flight["fares"]
    eco = fares.get("A") or fares.get("B") or fares.get("C")
    flex = fares.get("B")
    biz = fares.get("C")
    if eco is None:
        return {"error": "no_fares", "message": "Uygun fare bulunamadi."}

    bundles = [
        _bundle_from(eco, [], adults, children, babies, "Ekonomik Paket", "Economy Bundle", 0.0),
        _bundle_from(eco, ["bag_23kg", "seat_aisle", "priority_boarding"], adults, children, babies,
                     "Önerilen Paket", "Recommended Bundle", 0.25), # 25% discount
    ]
    comfort_fare = flex or biz
    if comfort_fare is not None:
        bundles.append(
            _bundle_from(comfort_fare, ["bag_23kg", "lounge"], adults, children, babies,
                         "Konfor Paketi", "Comfort Bundle", 0.30) # 30% discount
        )

    return {
        "flight": {
            "flight_number": flight["flight_number"], "date": flight["date"],
            "hour": flight["hour"], "origin": res["origin"], "destination": res["destination"],
            "trip_type": res["trip_type"],
        },
        "passengers": res["passengers"],
        "seat_passengers": res["seat_passengers"],
        "bundles": bundles,
        "note": "Tum fiyatlar ve urunler sistemden gelir; AI yalnizca birlestirir ve aciklar.",
    }


def get_availability(availability_id: int) -> dict | None:
    conn = domain.connect()
    row = conn.execute(
        "SELECT a.*, f.flight_number, f.date, f.hour FROM availabilities a"
        " JOIN flights f ON f.id = a.flight_id WHERE a.id = ?",
        (availability_id,),
    ).fetchone()
    conn.close()
    return dict(row) if row else None


def purchase_ticket(
    availability_id: int,
    first_name: str,
    last_name: str,
    passport_number: str,
    adults: int = 1,
    children: int = 0,
    babies: int = 0,
) -> dict:
    """Purchase (WRITE). Locks the seat row and guards against races.

    Laravel PurchaseTickets action'inin Python karsiligi: musaitlik satirini
    kilitler, koltuk yetmezse hata, yeterse PNR + her koltuk icin ticket
    olusturur ve count_available'i dusurur.
    """
    seat_passengers = adults + children
    conn = domain.connect()
    try:
        conn.isolation_level = None
        cur = conn.cursor()
        cur.execute("BEGIN IMMEDIATE")  # yazma kilidi (race condition korumasi)

        avail = cur.execute(
            "SELECT a.*, f.flight_number, f.date, f.hour FROM availabilities a"
            " JOIN flights f ON f.id = a.flight_id WHERE a.id = ?",
            (availability_id,),
        ).fetchone()
        if avail is None:
            cur.execute("ROLLBACK")
            return {"success": False, "error": "not_found", "message": "Fare bulunamadi."}

        if avail["count_available"] < seat_passengers:
            cur.execute("ROLLBACK")
            return {
                "success": False,
                "error": "insufficient_seats",
                "message": "Bu fare'de artik yeterli koltuk yok.",
                "seats_left": avail["count_available"],
                "seats_needed": seat_passengers,
            }

        cur.execute(
            "INSERT INTO pnrs (first_name, last_name, passport_number, created_at)"
            " VALUES (?, ?, ?, ?)",
            (first_name, last_name, passport_number.upper(), datetime.utcnow().isoformat()),
        )
        pnr_id = cur.lastrowid
        for _ in range(seat_passengers):
            cur.execute(
                "INSERT INTO tickets (availability_id, pnr_id, flown) VALUES (?, ?, 0)",
                (availability_id, pnr_id),
            )
        cur.execute(
            "UPDATE availabilities SET count_available = count_available - ? WHERE id = ?",
            (seat_passengers, availability_id),
        )
        cur.execute("COMMIT")

        breakdown = domain.price_breakdown(avail["base_price_usd"], adults, children, babies)
        return {
            "success": True,
            "pnr_id": pnr_id,
            "record_locator": f"DP{pnr_id:05d}",
            "passenger": f"{first_name} {last_name}",
            "flight_number": avail["flight_number"],
            "date": avail["date"],
            "class": avail["class"],
            "seats_booked": seat_passengers,
            "price_breakdown": breakdown,
        }
    finally:
        conn.close()
