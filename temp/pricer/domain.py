"""Pricer domain: database schema, deterministic seed data and pricing rules.

Ported from the Laravel `dynamic-pricer` application:
- Demand multiplier based on proximity to departure
- Round-trip = one-way * 0.88 (12% discount)
- Seat availability (count_available)
- Fare class attributes (baggage, change/refund penalties)

Plus the passenger-type pricing rules from the case study:
- Adult 100%, Child 75%, Infant 10% (no seat)
- Tax $240 per person, infants exempt
"""

from __future__ import annotations

import sqlite3
from datetime import date, timedelta
from pathlib import Path

DB_PATH = Path(__file__).resolve().parent.parent.parent / "data" / "pricer.sqlite3"

# --- Case-study rules: passenger-type multipliers and tax ---
CHILD_FARE_RATIO = 0.75
INFANT_FARE_RATIO = 0.10
TAX_PER_PAX_USD = 240  # infants exempt

AIRPORTS: list[tuple[str, str]] = [
    # Türkiye İç Hatlar (Domestic)
    ("IST", "İstanbul Havalimanı (IST)"),
    ("SAW", "İstanbul Sabiha Gökçen Havalimanı (SAW)"),
    ("ADB", "İzmir Adnan Menderes Havalimanı (ADB)"),
    ("ESB", "Ankara Esenboğa Havalimanı (ESB)"),
    ("AYT", "Antalya Havalimanı (AYT)"),
    ("DLM", "Muğla Dalaman Havalimanı (DLM)"),
    ("BJV", "Muğla Milas-Bodrum Havalimanı (BJV)"),
    ("TZX", "Trabzon Havalimanı (TZX)"),
    ("GZT", "Gaziantep Havalimanı (GZT)"),
    ("ADA", "Adana Şakirpaşa Havalimanı (ADA)"),
    ("GNY", "Şanlıurfa GAP Havalimanı (GNY)"),
    ("ERZ", "Erzurum Havalimanı (ERZ)"),
    ("VAN", "Van Ferit Melen Havalimanı (VAN)"),
    ("DIY", "Diyarbakır Havalimanı (DIY)"),
    ("KSY", "Kars Harakani Havalimanı (KSY)"),
    ("MLX", "Malatya Erhaç Havalimanı (MLX)"),
    ("ECN", "Kıbrıs Ercan Havalimanı (ECN)"),

    # Avrupa Yurtdışı Rotaları (Europe)
    ("LHR", "Londra Heathrow Havalimanı (LHR)"),
    ("LGW", "Londra Gatwick Havalimanı (LGW)"),
    ("CDG", "Paris Charles de Gaulle Havalimanı (CDG)"),
    ("AMS", "Amsterdam Schiphol Havalimanı (AMS)"),
    ("FRA", "Frankfurt Havalimanı (FRA)"),
    ("MUC", "Münih Havalimanı (MUC)"),
    ("BER", "Berlin Brandenburg Havalimanı (BER)"),
    ("DUS", "Düsseldorf Havalimanı (DUS)"),
    ("HAM", "Hamburg Havalimanı (HAM)"),
    ("ZRH", "Zürih Havalimanı (ZRH)"),
    ("GVA", "Cenevre Havalimanı (GVA)"),
    ("VIE", "Viyana Havalimanı (VIE)"),
    ("MXP", "Milano Malpensa Havalimanı (MXP)"),
    ("FCO", "Roma Fiumicino Havalimanı (FCO)"),
    ("MAD", "Madrid Barajas Havalimanı (MAD)"),
    ("BCN", "Barselona El Prat Havalimanı (BCN)"),
    ("LIS", "Lizbon Humberto Delgado Havalimanı (LIS)"),
    ("BRU", "Brüksel Havalimanı (BRU)"),
    ("ATH", "Atina Eleftherios Venizelos Havalimanı (ATH)"),
    ("BEG", "Belgrad Nikola Tesla Havalimanı (BEG)"),
    ("SJJ", "Saraybosna Havalimanı (SJJ)"),
    ("PRG", "Prag Vaclav Havel Havalimanı (PRG)"),
    ("BUD", "Budapeşte Ferenc Liszt Havalimanı (BUD)"),
    ("WAW", "Varşova Chopin Havalimanı (WAW)"),
    ("CPH", "Kopenhag Havalimanı (CPH)"),
    ("ARN", "Stockholm Arlanda Havalimanı (ARN)"),

    # Amerika & Kanada (Americas)
    ("JFK", "New York John F. Kennedy Havalimanı (JFK)"),
    ("ORD", "Chicago O'Hare Havalimanı (ORD)"),
    ("LAX", "Los Angeles Uluslararası Havalimanı (LAX)"),
    ("MIA", "Miami Uluslararası Havalimanı (MIA)"),
    ("SFO", "San Francisco Uluslararası Havalimanı (SFO)"),
    ("BOS", "Boston Logan Havalimanı (BOS)"),
    ("YYZ", "Toronto Pearson Havalimanı (YYZ)"),
    ("GRU", "São Paulo Guarulhos Havalimanı (GRU)"),

    # Orta Doğu & Afrika (Middle East & Africa)
    ("DXB", "Dubai Uluslararası Havalimanı (DXB)"),
    ("DOH", "Doha Hamad Uluslararası Havalimanı (DOH)"),
    ("JED", "Cidde Kral Abdülaziz Havalimanı (JED)"),
    ("MED", "Medine Prens Muhammed Havalimanı (MED)"),
    ("RUH", "Riyad Kral Halid Havalimanı (RUH)"),
    ("CAI", "Kahire Uluslararası Havalimanı (CAI)"),
    ("CMN", "Kozablanka Muhammed V Havalimanı (CMN)"),
    ("JNB", "Johannesburg O.R. Tambo Havalimanı (JNB)"),

    # Asya & Pasifik (Asia Pacific)
    ("SIN", "Singapur Changi Havalimanı (SIN)"),
    ("BKK", "Bangkok Suvarnabhumi Havalimanı (BKK)"),
    ("KUL", "Kuala Lumpur Uluslararası Havalimanı (KUL)"),
    ("NRT", "Tokyo Narita Havalimanı (NRT)"),
    ("HND", "Tokyo Haneda Havalimanı (HND)"),
    ("ICN", "Seul Incheon Havalimanı (ICN)"),
    ("PEK", "Pekin Başkent Havalimanı (PEK)"),
    ("DPS", "Bali Denpasar Ngurah Rai Havalimanı (DPS)"),
    ("TAS", "Taşkent Uluslararası Havalimanı (TAS)"),
    ("GYD", "Bakü Haydar Aliyev Havalimanı (GYD)"),
    ("TBS", "Tiflis Uluslararası Havalimanı (TBS)"),
]

# (origin, dest, base_price_usd, [hours])
ROUTES: list[tuple[str, str, int, list[str]]] = [
    # Avrupa Rotaları
    ("IST", "LHR", 180, ["07:35", "13:20", "18:55"]),
    ("LHR", "IST", 178, ["09:10", "15:45", "21:10"]),
    ("IST", "CDG", 150, ["06:50", "12:30", "19:25"]),
    ("CDG", "IST", 152, ["08:05", "14:00", "20:15"]),
    ("IST", "AMS", 145, ["08:45", "16:20"]),
    ("AMS", "IST", 143, ["10:25", "18:10"]),
    ("IST", "FRA", 135, ["07:10", "17:30"]),
    ("FRA", "IST", 138, ["11:20", "19:40"]),
    ("IST", "MUC", 140, ["08:30", "16:45"]),
    ("MUC", "IST", 142, ["11:15", "19:30"]),
    ("IST", "BER", 130, ["09:15", "17:20"]),
    ("BER", "IST", 128, ["11:45", "19:50"]),
    ("IST", "DUS", 135, ["08:00", "16:10"]),
    ("DUS", "IST", 133, ["10:30", "18:40"]),
    ("IST", "ZRH", 155, ["09:00", "17:15"]),
    ("ZRH", "IST", 153, ["12:00", "20:10"]),
    ("IST", "VIE", 130, ["08:10", "15:50"]),
    ("VIE", "IST", 132, ["10:45", "18:25"]),
    ("IST", "MXP", 160, ["07:45", "16:15"]),
    ("MXP", "IST", 158, ["10:30", "19:00"]),
    ("IST", "FCO", 165, ["08:15", "17:00"]),
    ("FCO", "IST", 162, ["11:00", "19:45"]),
    ("IST", "MAD", 175, ["08:50", "17:30"]),
    ("MAD", "IST", 172, ["11:40", "20:15"]),
    ("IST", "BCN", 170, ["09:10", "18:00"]),
    ("BCN", "IST", 168, ["12:00", "20:45"]),
    ("IST", "ATH", 95, ["07:30", "14:15", "21:00"]),
    ("ATH", "IST", 93, ["09:15", "16:00", "22:45"]),
    ("IST", "BEG", 110, ["08:20", "16:40"]),
    ("BEG", "IST", 108, ["10:15", "18:35"]),
    ("IST", "SJJ", 115, ["09:05", "17:10"]),
    ("SJJ", "IST", 112, ["11:00", "19:05"]),
    ("IST", "PRG", 140, ["08:35", "16:25"]),
    ("PRG", "IST", 138, ["10:50", "18:40"]),
    ("IST", "BUD", 125, ["08:40", "16:15"]),
    ("BUD", "IST", 122, ["10:40", "18:15"]),
    ("IST", "CPH", 150, ["09:20", "17:40"]),
    ("CPH", "IST", 148, ["11:50", "20:10"]),

    # Amerika Rotaları
    ("IST", "JFK", 520, ["06:45", "13:15", "18:20"]),
    ("JFK", "IST", 510, ["12:00", "23:45"]),
    ("IST", "ORD", 580, ["14:15", "21:00"]),
    ("ORD", "IST", 575, ["18:00", "23:45"]),
    ("IST", "LAX", 620, ["13:00", "19:50"]),
    ("LAX", "IST", 610, ["16:30", "22:15"]),
    ("IST", "MIA", 600, ["14:45", "21:30"]),
    ("MIA", "IST", 590, ["19:15", "23:55"]),
    ("IST", "SFO", 640, ["13:40", "20:15"]),
    ("SFO", "IST", 630, ["17:00", "22:40"]),
    ("IST", "BOS", 550, ["15:10", "21:45"]),
    ("BOS", "IST", 540, ["19:30", "23:50"]),
    ("IST", "YYZ", 560, ["14:30", "21:15"]),
    ("YYZ", "IST", 550, ["18:45", "23:30"]),

    # Orta Doğu & Afrika Rotaları
    ("IST", "DXB", 260, ["01:45", "14:15", "22:30"]),
    ("DXB", "IST", 255, ["03:05", "12:25", "20:50"]),
    ("IST", "DOH", 240, ["02:10", "15:30"]),
    ("DOH", "IST", 235, ["04:15", "17:45"]),
    ("IST", "JED", 280, ["01:15", "13:40", "21:10"]),
    ("JED", "IST", 275, ["04:00", "16:20", "23:50"]),
    ("IST", "CAI", 190, ["07:20", "18:15"]),
    ("CAI", "IST", 185, ["09:40", "20:30"]),
    ("IST", "CMN", 290, ["11:30", "20:45"]),
    ("CMN", "IST", 285, ["14:50", "23:55"]),

    # Asya Rotaları
    ("IST", "SIN", 480, ["01:55", "18:40"]),
    ("SIN", "IST", 475, ["11:30", "23:25"]),
    ("IST", "BKK", 450, ["01:30", "17:20"]),
    ("BKK", "IST", 445, ["10:45", "22:30"]),
    ("IST", "KUL", 460, ["01:45", "18:10"]),
    ("KUL", "IST", 455, ["11:15", "23:10"]),
    ("IST", "NRT", 650, ["02:20", "17:10"]),
    ("NRT", "IST", 640, ["11:45", "22:30"]),
    ("IST", "ICN", 540, ["01:40", "17:50"]),
    ("ICN", "IST", 530, ["11:00", "23:00"]),
    ("IST", "DPS", 680, ["01:50", "18:30"]),
    ("DPS", "IST", 670, ["12:15", "23:45"]),
    ("IST", "GYD", 170, ["08:15", "16:50", "23:20"]),
    ("GYD", "IST", 168, ["10:40", "19:15"]),
    ("IST", "TBS", 160, ["07:45", "15:30"]),
    ("TBS", "IST", 158, ["10:00", "17:45"]),
    ("IST", "TAS", 260, ["01:25", "17:40"]),
    ("TAS", "IST", 255, ["07:15", "23:10"]),

    # Türkiye İç Hat Rotaları
    ("IST", "ADB", 45, ["06:00", "10:30", "15:45", "21:15"]),
    ("ADB", "IST", 45, ["08:15", "12:50", "18:10", "23:20"]),
    ("IST", "ESB", 40, ["07:00", "11:30", "16:00", "20:30"]),
    ("ESB", "IST", 40, ["09:00", "13:30", "18:00", "22:30"]),
    ("IST", "AYT", 50, ["06:30", "12:00", "17:30", "22:00"]),
    ("AYT", "IST", 50, ["08:45", "14:15", "19:45"]),
    ("IST", "DLM", 48, ["07:10", "16:40"]),
    ("DLM", "IST", 48, ["09:20", "18:50"]),
    ("IST", "BJV", 48, ["06:50", "17:15"]),
    ("BJV", "IST", 48, ["09:05", "19:30"]),
    ("IST", "TZX", 52, ["06:15", "14:50", "21:00"]),
    ("TZX", "IST", 52, ["08:30", "17:05", "23:15"]),
    ("IST", "GZT", 55, ["07:05", "16:20"]),
    ("GZT", "IST", 55, ["09:25", "18:40"]),
    ("SAW", "ADB", 42, ["07:15", "14:30", "19:45"]),
    ("ADB", "SAW", 42, ["09:20", "16:40", "21:50"]),
    ("SAW", "AYT", 48, ["06:45", "13:10", "18:25"]),
    ("AYT", "SAW", 48, ["09:00", "15:25", "20:40"]),
]

# Fare class templates (mirrors Laravel Flight::defaultAvailabilityForClass)
CLASS_TEMPLATES: dict[str, dict] = {
    # Turkish Airlines branded fare families. Feature matrix follows the official
    # branded-fares announcement (see data/sources/01-thy-branded-fares-2022.pdf).
    "ECO": {
        "label": "EcoFly",
        "cabin": "economy",
        "checked_baggage_kg": 0,           # no free checked bag
        "cabin_baggage_kg": 8,
        "seat_selection_free": 0,
        "seat_label": None,
        "miles": 1108,
        "fast_track": 0,
        "same_day_change": 0,
        "latest_change_hours": None,       # no change right
        "latest_refund_hours": None,       # no refund right
        "base_count": 20,
        "price_offset": 0,
        "penalty_is_full_fare": True,
    },
    "EXTRA": {
        "label": "ExtraFly",
        "cabin": "economy",
        "checked_baggage_kg": 23,
        "cabin_baggage_kg": 8,
        "seat_selection_free": 0,
        "seat_label": None,
        "miles": 1108,
        "fast_track": 0,
        "same_day_change": 0,
        "latest_change_hours": 12,         # change allowed, with a fee
        "latest_refund_hours": None,       # no refund right
        "base_count": 16,
        "price_offset": 55,
        "change_fee_usd": 95,
        "refund_fee_usd": 0,
        "penalty_is_full_fare": False,
    },
    "FLEX": {
        "label": "FlexFly",
        "cabin": "economy",
        "checked_baggage_kg": 23,
        "cabin_baggage_kg": 8,
        "seat_selection_free": 1,
        "seat_label": "standard",
        "miles": 1385,
        "fast_track": 0,
        "same_day_change": 0,
        "latest_change_hours": 12,         # free change
        "latest_refund_hours": 12,         # refund with penalty
        "base_count": 12,
        "price_offset": 90,
        "change_fee_usd": 0,
        "refund_fee_usd": 122,
        "penalty_is_full_fare": False,
    },
    "PRIME": {
        "label": "PrimeFly",
        "cabin": "economy",
        "checked_baggage_kg": 30,
        "cabin_baggage_kg": 8,
        "seat_selection_free": 1,
        "seat_label": "preferred",
        "miles": 1662,
        "fast_track": 1,
        "same_day_change": 1,
        "latest_change_hours": 6,          # free change
        "latest_refund_hours": 6,          # free refund
        "base_count": 9,
        "price_offset": 165,
        "change_fee_usd": 0,
        "refund_fee_usd": 0,
        "penalty_is_full_fare": False,
    },
    "BFLY": {
        "label": "BusinessFly",
        "cabin": "business",
        "checked_baggage_kg": 30,
        "cabin_baggage_kg": 16,
        "seat_selection_free": 1,
        "seat_label": "standard",
        "miles": 2216,
        "fast_track": 1,
        "same_day_change": 0,
        "latest_change_hours": 6,
        "latest_refund_hours": None,
        "base_count": 8,
        "price_offset": 380,
        "change_fee_usd": 140,
        "refund_fee_usd": 0,
        "penalty_is_full_fare": False,
    },
    "BPRIME": {
        "label": "BusinessPrime",
        "cabin": "business",
        "checked_baggage_kg": 40,
        "cabin_baggage_kg": 16,
        "seat_selection_free": 1,
        "seat_label": "preferred",
        "miles": 3324,
        "fast_track": 1,
        "same_day_change": 1,
        "latest_change_hours": 3,
        "latest_refund_hours": 3,
        "base_count": 5,
        "price_offset": 560,
        "change_fee_usd": 0,
        "refund_fee_usd": 0,
        "penalty_is_full_fare": False,
    },
}

SEED_DAYS = 25


def demand_multiplier(day_offset: int) -> float:
    """Prices rise as departure approaches (dynamic pricing)."""
    if day_offset <= 2:
        return 1.45
    if day_offset <= 7:
        return 1.25
    if day_offset <= 14:
        return 1.10
    return 1.00


def round_trip_price(one_way_usd: int) -> int:
    """Round trips are 12% cheaper than one-way (case insight: RT < 2x one-way)."""
    return int(round(one_way_usd * 0.88))


def _seat_count(base_count: int, day_offset: int, seed: int) -> int:
    """Seat count that becomes scarce as departure approaches.

    Deterministic (seed = flight id) so demos are repeatable. Some flights are
    left deliberately tight to exercise the "not enough seats" guardrail.
    """
    if day_offset <= 2:
        drop = 12
    elif day_offset <= 7:
        drop = 8
    elif day_offset <= 14:
        drop = 4
    else:
        drop = 0
    jitter = seed % 4  # 0..3
    return max(1, base_count - drop - jitter)


def connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


SCHEMA = """
CREATE TABLE IF NOT EXISTS airports (
    id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT
);
CREATE TABLE IF NOT EXISTS flights (
    id INTEGER PRIMARY KEY, date TEXT, hour TEXT,
    origin_id INTEGER, dest_id INTEGER, flight_number TEXT, plane_model TEXT
);
CREATE TABLE IF NOT EXISTS availabilities (
    id INTEGER PRIMARY KEY, flight_id INTEGER, class TEXT, class_letters TEXT,
    fare_type TEXT, base_price_usd INTEGER, count_available INTEGER,
    checked_baggage_kg INTEGER, cabin_baggage_kg INTEGER, seat_selection_free INTEGER,
    change_fee_usd INTEGER, refund_fee_usd INTEGER,
    latest_change_hours INTEGER, latest_refund_hours INTEGER,
    cabin TEXT, seat_label TEXT, miles INTEGER, fast_track INTEGER, same_day_change INTEGER,
    FOREIGN KEY (flight_id) REFERENCES flights(id)
);
CREATE TABLE IF NOT EXISTS pnrs (
    id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
    passport_number TEXT, created_at TEXT
);
CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY, availability_id INTEGER, pnr_id INTEGER, flown INTEGER DEFAULT 0
);
"""


def plane_for_route(origin: str, dest: str) -> str:
    longhaul = {"JFK", "SIN", "DXB"}
    if origin in longhaul or dest in longhaul:
        return "Boeing 777-300ER"
    return "Airbus A321neo"


def seed(force: bool = False) -> None:
    """Create the database and fill it with deterministic demo data."""
    conn = connect()
    cur = conn.cursor()
    cur.executescript(SCHEMA)

    already = cur.execute("SELECT COUNT(*) AS c FROM flights").fetchone()["c"]
    if already and not force:
        conn.close()
        return
    if force:
        cur.executescript(
            "DELETE FROM tickets; DELETE FROM pnrs; DELETE FROM availabilities;"
            " DELETE FROM flights; DELETE FROM airports;"
        )

    airport_ids: dict[str, int] = {}
    for code, name in AIRPORTS:
        cur.execute("INSERT INTO airports (code, name) VALUES (?, ?)", (code, name))
        airport_ids[code] = cur.lastrowid

    flight_seq = 100
    today = date.today()

    for day_offset in range(SEED_DAYS):
        d = today + timedelta(days=day_offset)
        mult = demand_multiplier(day_offset)
        for origin, dest, base_price, hours in ROUTES:
            for hour in hours:
                cur.execute(
                    "INSERT INTO flights (date, hour, origin_id, dest_id, flight_number, plane_model)"
                    " VALUES (?, ?, ?, ?, ?, ?)",
                    (
                        d.isoformat(),
                        hour,
                        airport_ids[origin],
                        airport_ids[dest],
                        f"DP{flight_seq}",
                        plane_for_route(origin, dest),
                    ),
                )
                flight_id = cur.lastrowid
                flight_seq += 1
                _seed_availabilities(cur, flight_id, base_price, mult, day_offset)

    conn.commit()
    conn.close()


def _seed_availabilities(cur, flight_id: int, base_price: int, mult: float, day_offset: int) -> None:
    for letter, tpl in CLASS_TEMPLATES.items():
        one_way_price = int(round((base_price + tpl["price_offset"]) * mult))
        for fare_type in ("one_way", "round_trip"):
            price = round_trip_price(one_way_price) if fare_type == "round_trip" else one_way_price
            if tpl["penalty_is_full_fare"]:
                change_fee = price
                refund_fee = price
            else:
                change_fee = tpl.get("change_fee_usd", 0)
                refund_fee = tpl.get("refund_fee_usd", 0)
            count = _seat_count(tpl["base_count"], day_offset, seed=flight_id + sum(map(ord, letter)))
            if fare_type == "round_trip":
                count = max(1, int(round(count * 0.75)))
                class_letters = f"{letter}(R)"
                label = f"{tpl['label']} Roundtrip"
            else:
                class_letters = letter
                label = tpl["label"]
            cur.execute(
                "INSERT INTO availabilities (flight_id, class, class_letters, fare_type,"
                " base_price_usd, count_available, checked_baggage_kg, cabin_baggage_kg,"
                " seat_selection_free, change_fee_usd, refund_fee_usd, latest_change_hours,"
                " latest_refund_hours, cabin, seat_label, miles, fast_track, same_day_change)"
                " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                (
                    flight_id, label, class_letters, fare_type, price, count,
                    tpl["checked_baggage_kg"], tpl["cabin_baggage_kg"], tpl["seat_selection_free"],
                    change_fee, refund_fee, tpl["latest_change_hours"], tpl["latest_refund_hours"],
                    tpl["cabin"], tpl["seat_label"], tpl["miles"], tpl["fast_track"],
                    tpl["same_day_change"],
                ),
            )


def price_breakdown(base_price_usd: int, adults: int, children: int, babies: int) -> dict:
    """Total family price using the case-study rules.

    Adult 100%, child 75%, infant 10% of the base fare; tax $240 per person
    (infants exempt).
    """
    adult_fare = base_price_usd
    child_fare = int(round(base_price_usd * CHILD_FARE_RATIO))
    infant_fare = int(round(base_price_usd * INFANT_FARE_RATIO))
    fares_subtotal = adults * adult_fare + children * child_fare + babies * infant_fare
    taxed_pax = adults + children  # infants are tax-exempt
    taxes = taxed_pax * TAX_PER_PAX_USD
    return {
        "adult_fare_usd": adult_fare,
        "child_fare_usd": child_fare,
        "infant_fare_usd": infant_fare,
        "adults": adults,
        "children": children,
        "babies": babies,
        "fares_subtotal_usd": fares_subtotal,
        "taxes_usd": taxes,
        "tax_per_pax_usd": TAX_PER_PAX_USD,
        "grand_total_usd": fares_subtotal + taxes,
    }
